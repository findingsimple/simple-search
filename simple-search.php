<?php
/*
Plugin Name: Simple Search
Description: Give your visitors what they're looking for. 
Author: _FindingSimple
Author URI: http://findingsimple.com/
Version: 1.0

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/
/**
 * @package Simple Search
 * @version 1.0
 * @author Brent Shepherd <brent@findingsimple.com>
 * @copyright Copyright (c) 2012 Finding Simple
 * @link http://findingsimple.com/
 * @license http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 */

if ( ! class_exists( 'FS_Simple_Search' ) ) :

/**
 * So that themes and other plugins can customise the text domain, the FS_Simple_Search
 * should not be initialized until after the plugins_loaded and after_setup_theme hooks.
 * However, it also needs to run early on the init hook.
 *
 * @author Brent Shepherd <brent@findingsimple.com>
 * @package Simple Search
 * @since 1.0
 */
function fs_initialize_search(){
	FS_Simple_Search::init();
}
add_action( 'init', 'fs_initialize_search', -1 );

class FS_Simple_Search {

	static $text_domain;

	/* DB option/meta prefixes */

	static $tally_prefix        = '_fss_search_tally_';

	static $relevance_prefix    = '_fss_relevance_';

	static $did_you_mean_prefix = '_fss_did_you_mean_';

	/* Relevance Weights based on SEOmoz's Importance Scale */

	static $short_circuit_importance = 100;
	static $very_high_importance     = 13;
	static $high_importance          = 8;
	static $moderate_importance      = 5;
	static $low_importance           = 3;
	static $minimal_importance       = 2;

	/* Flag for preventing infinite loops */

	static $mid_search = false;

	/**
	 * Hook into WordPress where appropriate.
	 * 
	 * @author Brent Shepherd <brent@findingsimple.com>
	 * @package Simple Search
	 * @since 1.0
	 */
	public static function init() {

		self::$text_domain = apply_filters( 'simple_search_text_domain', 'Simple_Search' );

		add_filter( 'search_link', __CLASS__ . '::search_link', 10, 2 );

		add_filter( 'pre_get_posts', __CLASS__ . '::ensure_relevance_index_exists', 10, 2 );

		add_filter( 'parse_query', __CLASS__ . '::order_by_relevance_value', 10, 2 );

		add_filter( 'get_the_excerpt', __CLASS__ . '::get_search_excerpt', 1 );

		add_filter( 'breadcrumb_trail_items', __CLASS__ . '::breadcrumb_trail_items', 10, 2 );

		add_filter( 'posts_search', __CLASS__ . '::search_all', 10, 2 );

		add_filter( 'loop_pagination_args', __CLASS__ . '::add_filters_to_page_links' );

		add_action( 'init', __CLASS__ . '::maybe_redirect_search' );

		add_action( 'parse_query', __CLASS__ . '::fix_query' );

		add_filter( 'wp_title', __CLASS__ . '::seach_all_title' );

		add_action( 'init', __CLASS__ . '::make_pages_queryable', 20 );

		add_action( 'save_post', __CLASS__ . '::schedule_relevance_index_building', 10, 2 );

		add_action( 'fss_build_post_relevance_index', __CLASS__ . '::build_post_relevance_index' );

		add_action( 'fss_build_post_relevance_index', __CLASS__ . '::reschedule_relevance_index' );

		add_action( 'shutdown', __CLASS__ . '::search_query_tally' );

		add_action( 'fss_clean_search_cache', __CLASS__ . '::clean_search_index' );
		add_action( 'fss_clean_search_index', __CLASS__ . '::clean_search_index' );

		add_filter( 'cron_schedules', __CLASS__ . '::add_custom_cron_schedules' );
	}

	/**
	 * Boolean to checks if the current query is a search query OR the current page is
	 * using a custom search template.
	 * 
	 * @author Brent Shepherd <brent@findingsimple.com>
	 * @package Simple Search
	 * @since 1.0
	 */
	public static function is_search() {
		global $wp_query;

		if( is_search() )
			return true;
		elseif( isset( $wp_query->post->ID ) && 'page-template-search.php' == get_post_meta( $wp_query->post->ID, '_wp_page_template', true ) )
			return true;
		else
			return false;
	}


	/**
	 * Uses the TinyMCE spell checker for a future-proof method of
	 * checking spelling.
	 * 
	 * @author Brent Shepherd <brent@findingsimple.com>
	 * @package Simple Search
	 * @since 1.0
	 */
	public static function did_you_mean() {
		global $wp_query;

		// Only run spell checker on search page & when less than 10 results found
		if( ! is_search() || $wp_query->found_posts > 10 )
			return;

		$search_query = urldecode( get_search_query( false ) );

		$search_query = self::remove_punctuation( $search_query );

		$did_you_mean = get_option( self::get_search_query_key( $search_query, self::$did_you_mean_prefix ), array() );

		// Only calculate did_you_mean if none already indexed
		if ( empty( $did_you_mean ) ) {

			$search_query_tokens = explode( " ", $search_query) ;

			$lang = 'en';

			$config = array( 'general.engine' => 'GoogleSpell' );

			require_once( ABSPATH . WPINC . "/js/tinymce/plugins/spellchecker/classes/SpellChecker.php" );
			require_once( ABSPATH . WPINC . "/js/tinymce/plugins/spellchecker/classes/GoogleSpell.php" );

			// Create an instance of the spell checker (same was as TinyMCE does it)
			$spellchecker = new $config['general.engine']($config);
			$misspellings = $spellchecker->checkWords( $lang, $search_query_tokens ); //$result = call_user_func_array(array($spellchecker, $input['method']), $input['params']);

			if( empty( $misspellings ) )
				return;

			// Get the spelling suggestions for each mispelled word
			foreach( $misspellings as $misspelling ) {
				$all_suggestions		   = $spellchecker->getSuggestions( $lang, $misspelling );
				$suggestions[$misspelling] = $all_suggestions[0];
			}

			// Create an string with each word & recommended word
			foreach( $search_query_tokens as $search_term )
					$did_you_mean[] = ( isset( $suggestions[$search_term] ) ) ? $suggestions[$search_term] : $search_term;

			$did_you_mean = implode( ' ', $did_you_mean );

			update_option( self::get_search_query_key( $search_query, self::$did_you_mean_prefix ), $did_you_mean );

		}

	// Output the suggestion using add_query_arg for search link instead of get_search_link to have filter parameters persist
	?>
	<div class="dym">
		<?php _e( 'Did you mean: ', self::$text_domain ); ?><a href="<?php echo add_query_arg( array( 's' => urlencode( $did_you_mean ) ) ) ?>"><?php echo $did_you_mean; ?></a>?
	</div>
	<?php

		return $did_you_mean;
	}


	/**
	 * Returns a string detailing the number of search results and the result currently being displayed. eg. Showing 1-10 of 100 results
	 * 
	 * @author Brent Shepherd <brent@findingsimple.com>
	 * @package Simple Search
	 * @since 1.0
	 */
	public static function the_search_results_count() {
		global $wp_query;

		if( $wp_query->found_posts > 0 ) {

			$previous_results = ( $wp_query->query_vars['paged'] == 0 ) ? 1 : ( $wp_query->query_vars['paged'] - 1 ) * $wp_query->query_vars['posts_per_page'] + 1;
	?>

	<div class="search-results-count">
		<?php printf( __( 'Showing %s-%s of %s results', self::$text_domain ), $previous_results, $previous_results + $wp_query->post_count - 1, $wp_query->found_posts ); ?>
	</div>

	<?php

		}

	}


	/**
	 * Before getting posts for a search query, make sure that the a relevance index for
	 * that search query exists.
	 *
	 * @author Brent Shepherd <brent@findingsimple.com>
	 * @package Simple Search
	 * @since 2.0
	 */
	public static function ensure_relevance_index_exists( &$query ) {
		global $wpdb;

		if ( true == $query->is_search && '~' != $query->query_vars['s'] ) {

			$search_query     = get_search_query( false );
			$search_query_key = self::get_search_query_key( $search_query, self::$relevance_prefix );

			$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->postmeta WHERE meta_key = %s", $search_query_key ) );

			if ( $count <= 0 ) {

				// Get all posts IDs (just IDs to avoid memory exhaustion)
				$all_post_ids = get_posts( array(
					'numberposts' => -1,
					'post_type'   => 'any',
					'fields'      => 'ids',
					)
				);

				foreach ( $all_post_ids as $post_id ) {
					$post_to_check = get_post( $post_id );
					self::calculate_relevance_for_post( $post_to_check, $search_query );
				}
			}
		}
	}


	/**
	 * Order search results by pre-calculated relevance.
	 *
	 * @author Brent Shepherd <brent@findingsimple.com>
	 * @package Simple Search
	 * @since 2.0
	 */
	public static function order_by_relevance_value( &$query ) {

		if ( true == $query->is_search && '~' != $query->query_vars['s'] ) { // can't use is_search() as it returns true for sub (non-search) queries
			$search_query_meta_key = self::get_search_query_key( get_search_query( false ), self::$relevance_prefix );
			$query->set( 'meta_key', $search_query_meta_key );
			$query->set( 'orderby', 'meta_value_num' );
			$query->set( 'order', 'DESC' );
		}

	}


	/**
	 * Keep a tally of the number of times this query has been searched for. 
	 * 
	 * Hooked to shutdown so that it only fires once.
	 * 
	 * @author Brent Shepherd <brent@findingsimple.com>
	 * @package Simple Search
	 * @since 1.0
	 */
	public static function search_query_tally() {

		if( ! is_search() )
			return;

		$search_query = get_search_query( false );

		// Only count when there is actually a search query, and count once (for front page)
		if( empty( $search_query ) || $search_query == '~' || is_paged() )
			return;

		$search_tally = get_option( self::get_search_query_key( $search_query, self::$tally_prefix ), 0 );

		update_option( self::get_search_query_key( $search_query, self::$tally_prefix ), $search_tally + 1 );

		// Make sure the search index is being cleaned once per month
		if ( ! wp_next_scheduled( 'fss_clean_search_index' ) )
			wp_schedule_event( current_time( 'timestamp' ), 'monthly', 'fss_clean_search_index' );
	}


	/**
	 * Determines the relevance score for a given post & search query. 
	 * 
	 * In determining relevance, a number of factors are used, including the post's title, taxonomy terms, permalink
	 * comments & incoming links (trackbacks).
	 * 
	 * All factors & relevance weights are based on SEOmoz's reverse engineering of Google's PageRank algorithm through the 
	 * Importance Scale found here: http://www.seomoz.org/article/search-ranking-factors/2009#ranking-factors
	 * 
	 * Relevance factors based on SEOmoz Importance Scale
	 * 13 - 65% – 100%= very high importance
	 * 8  - 55% – 64%= high importance
	 * 5  - 45% – 54%= moderate importance
	 * 3  - 35% – 44%= low importance
	 * 2  - 25% – 34%= minimal importance
	 * 1  - 0% – 24%= very minimal
	 * 
	 * @author Brent Shepherd <brent@findingsimple.com>
	 * @package Simple Search
	 * @since 1.0
	 */
	public static function calculate_relevance_for_post( $post_to_check, $search_query ) {
		global $post;

		if( isset( $post_to_check->relevance ) ) // Already calculated relevance for this post 
			return $post_to_check->relevance;

		$post = $post_to_check;

		$relevance = get_post_meta( $post->ID, self::get_search_query_key( $search_query, self::$relevance_prefix ), true );

		if ( ! empty( $relevance ) ) {
			$post->relevance = $relevance;
			return $relevance;
		}

		$relevance = 1;

		// Ignore all punctuation & invisibles in search terms & post items
		$post_title   = strtolower( self::remove_punctuation( self::strip_invisibles( $post->post_title ) ) );
		$post_content = apply_filters( 'the_content', $post->post_content );
		$post_content = strtolower( self::remove_punctuation( self::strip_invisibles( $post_content ) ) );
		$search_query = strtolower( self::remove_punctuation( $search_query ) );

		$search_query_tokens = array_unique( explode( " ", $search_query) );

		$search_query_term_count = count( $search_query_tokens );

		$post_content_word_count = count( $search_query_tokens );

		// URL
		$permalink = get_permalink( $post->ID );

		// Array to store flags for which checks should be carried out on individual search terms
		$single_checks = array();


		/* On-Page (Keyword-Specific) Ranking Factors */

		// Search Query is title or appears in title
		if( 0 === strcasecmp( $post_title, $search_query ) )
			$relevance += self::$short_circuit_importance;
		elseif( substr_count( $post_title, $search_query ) > 0 )
			$relevance += self::$very_high_importance;
		else
			$single_checks[] = 'title';

		// Search Query as the First Word(s) of the Title Tag
		if( substr( $post_title, 0, strlen( $search_query ) ) == $search_query )
			$relevance += self::$very_high_importance;
		else
			$single_checks[] = 'title-beginning';

		// Search Query in the First 50 Words of post content
		preg_match( '/\s*(?:\S*\s*){0,50}/', $post_content, $first_fifty );

		if( substr_count( $first_fifty[0], $search_query ) > 0 )
			$relevance += self::$moderate_importance;
		else
			$single_checks[] = 'first-fifty';

		// Search Query in the Page Name URL
		if( substr_count( $permalink, $search_query ) > 0 )
			$relevance += self::$low_importance;
		else
			$single_checks[] = 'permalink';

		// Search Query Frequency
		$search_occurances = substr_count( $post_content, $search_query ); 
		if( $search_occurances > 0 )
			$relevance += ( $search_occurances > 100 ) ? self::$minimal_importance : self::$minimal_importance * $search_occurances / 100;
		else
			$single_checks[] = 'frequency';

		// Search Query in content
		$search_density = $search_occurances / $post_content_word_count; 
		if( $search_density > 0 )
			$relevance += ( $search_density > 1 ) ? self::$minimal_importance : self::$minimal_importance * $search_density;
		else
			$single_checks[] = 'density';

		// Search Query in taxonomy terms
		$object_taxonomies = get_object_taxonomies( $post->post_type );
		if( ! empty( $object_taxonomies ) ) {
			$taxonomy_terms = wp_get_object_terms( $post->ID, get_object_taxonomies( $post->post_type ), array( 'fields' => 'names' ) );

			if( in_array( $search_query, $taxonomy_terms ) )
				$relevance += self::$minimal_importance;
			else
				$single_checks[] = 'taxonomy';
		}

		// Now run checks for individual search terms (in 2 loops instead of 7 loops if done without the $single_checks flags)
		if( $search_query_term_count > 1 ) {

			foreach( $search_query_tokens as $search_token ) {

				$search_token_relevance = get_post_meta( $post->ID, self::get_search_query_key( $search_token, self::$relevance_prefix ), true );

				if ( ! empty( $search_token_relevance ) ) {
					$relevance += $search_token_relevance;
					continue;
				}

				// Ignore all search terms with < 3 letters or stop words
				if( strlen( $search_token ) < 3 || in_array( $search_token, self::get_stop_words() ) )
					continue;

				foreach( $single_checks as $check ) {

					switch( $check ) {
						case 'title' : 
							if( substr_count( $post_title, $search_token ) > 0 )
								$relevance += self::$very_high_importance / $search_query_term_count; // Add a maximum of 13
							break;
						case 'title-beginning' : 
							if( substr( $post_title, 0, strlen( $search_token ) ) == $search_token )
								$relevance += self::$very_high_importance / $search_query_term_count;
							break;
						case 'first-fifty' : 
							if( substr_count( $first_fifty[0], $search_token ) > 0 )
								$relevance += self::$moderate_importance / $search_query_term_count; // Add a maximum of 5
							break;
						case 'permalink' : 
							if( substr_count( $permalink, $search_token ) > 0 )
								$relevance += self::$low_importance / $search_query_term_count; // Add a maximum of 3
							break;
						case 'frequency' : 
							$search_term_occurances = substr_count( $post_content, $search_token ); 
							if( $search_term_occurances > 0 )
								$relevance += ( $search_term_occurances > 100 ) ? self::$minimal_importance / $search_query_term_count : self::$minimal_importance / $search_query_term_count * $search_term_occurances / 100;
							break;
						case 'density' :
							$search_term_density = substr_count( $post_content, $search_token ) / $post_content_word_count; 
							if( $search_term_density > 0 )
								$relevance += ( $search_term_density > 1 ) ? self::$minimal_importance / $search_query_term_count : self::$minimal_importance / $search_query_term_count * $search_term_density;
							break;
						case 'taxonomy' :
							if( ! empty( $object_taxonomies ) && in_array( $search_token, $taxonomy_terms ) )
								$relevance += self::$minimal_importance / $search_query_term_count;
							break;
						default :
							break;
					}
				}
			}
		}

		/* Non-Search Term Ranking Factors */

		// Historical Content Changes
		$revision_increment = self::$low_importance * count( wp_get_post_revisions( $post->ID ) ) / 100;
		$relevance += ( $revision_increment > self::$low_importance ) ? self::$low_importance : $revision_increment;

		// Number of Comments on post
		$comment_count = get_comments_number( $post->ID );
		if( $comment_count > 0 )
			$relevance += ( $comment_count > 100 ) ? self::$low_importance : self::$low_importance * $comment_count / 100;

		$post->relevance = $relevance;

		// Store the relevance of this post against the search query
		update_post_meta( $post->ID, self::get_search_query_key( $search_query, self::$relevance_prefix ), $relevance );

		return $relevance;
	}


	/**
	 * On search result pages, this function creates a custom excerpt for the current post which includes the 
	 * words in the current search query.
	 * 
	 * Based on the relevanssi relevanssi_do_excerpt & relevanssi_create_excerpt functions
	 * 
	 * @author Brent Shepherd <brent@findingsimple.com>
	 * @package Simple Search
	 * @since 1.0
	 */
	public static function get_search_excerpt( $original_excerpt ) {
		global $post;

		if( ! is_search() || self::$mid_search === true )
			return $original_excerpt;

		self::$mid_search = true;

		$search_query = urldecode( get_search_query() );

		if( empty( $search_query ) || $search_query == '~' )
			return;

		$terms = array_unique( explode( " ", self::remove_punctuation( $search_query ) ) );

		$content = apply_filters( 'the_content', $post->post_content );

		$content = do_shortcode( $content );

		$content = self::strip_invisibles( $content ); // removes <script>, <embed> &c with content
		$content = strip_tags( $content ); // this removes the tags, but leaves the content

		$content = preg_replace( "/\n\r|\r\n|\n|\r/", " ", $content );

		$excerpt_length = apply_filters( 'excerpt_length', 55 );

		$best_excerpt_term_hits = -1;
		$excerpt = "";

		$content = " $content";	
		$start = false;

		$words = explode( ' ', $content );

		$i = 0;

		while( $i < count( $words ) ) {

			if ( $i + $excerpt_length > count( $words ) )
				$i = count( $words ) - $excerpt_length;

			$excerpt_slice = array_slice( $words, $i, $excerpt_length );
			$excerpt_slice = implode( ' ', $excerpt_slice );

			$excerpt_slice = " $excerpt_slice";
			$term_hits = 0;

			foreach( array_keys( $terms ) as $term ) {
				$term = " $term";
				$pos = ( "" == $excerpt_slice ) ? false : stripos( $excerpt_slice, $term );
				if ( false === $pos ) {
					$titlecased = strtoupper( substr( $term, 0, 1 ) ) . substr( $term, 1 );
					$pos = strpos( $excerpt_slice, $titlecased );
					if ( false === $pos ) {
						$pos = strpos( $excerpt_slice, strtoupper( $term ) );
					}
				}

				if( false !== $pos ) {
					$term_hits++;
					if ( 0 == $i ) $start = true;
					if ( $term_hits > $best_excerpt_term_hits ) {
						$best_excerpt_term_hits = $term_hits;
						$excerpt = $excerpt_slice;
					}
				}
			}

			$i += $excerpt_length;
		}

		if ( "" == $excerpt ) {
			$excerpt = explode( ' ', $content, $excerpt_length );
			array_pop( $excerpt );
			$excerpt = implode( ' ', $excerpt );
			$start = true;
		}

		$content = apply_filters( 'the_excerpt', $content );	

		$excerpt = self::search_highlight_terms( $excerpt, $search_query );

		if ( ! $start )
			$excerpt = "..." . $excerpt;

		$excerpt = $excerpt . "...";

		self::$mid_search = false;

		return $excerpt;
	}


	/**
	 * Highlights the words in parameter one which match words in parameter two. Highlighting is done with HTML5 <mark> tag.
	 * 
	 * Based on the relevanssi relevanssi_highlight_terms & relevanssi_remove_nested_highlights functions
	 * 
	 * @author Brent Shepherd <brent@findingsimple.com>
	 * @package Simple Search
	 * @since 1.0
	 */
	public static function search_highlight_terms( $excerpt, $search_query ) {

		$start_emp = "<mark>";
		$end_emp = "</mark>";

		$start_emp_token = "*[/";
		$end_emp_token = "\]*";

		$terms = array_unique( explode( " ", self::remove_punctuation( $search_query ) ) );

		foreach( $terms as $term )
			$excerpt = preg_replace("/(\b$term\b)(?!([^<]+)?>)/iu", $start_emp_token . '\\1' . $end_emp_token, $excerpt);

		$excerpt = str_replace($start_emp_token, $start_emp, $excerpt);

		$excerpt = str_replace($end_emp_token, $end_emp, $excerpt);

		$excerpt = str_replace($end_emp . $start_emp, "", $excerpt);

		if (function_exists('mb_ereg_replace')) {
			$pattern = $end_emp . '\s*' . $start_emp;
			$excerpt = mb_ereg_replace($pattern, " ", $excerpt);
		}

		return $excerpt;
	}


	/**
	 * Simplifies the breadcrumbs on a search page.
	 * 
	 * @author Brent Shepherd <brent@findingsimple.com>
	 * @package Simple Search
	 * @since 1.0
	 */
	public static function breadcrumb_trail_items( $trail, $args ) {
		global $wp_query;

		// Search Results or Search 
		if( self::is_search() ) {
			$search_trail[] = array_shift( $trail );
			if( is_search() && $wp_query->query_vars['s'] != '~' ) {
				$search_trail[] = '<a href="' . get_search_link( '~' ) . '" title="Search">Search</a>';
				$search_trail['trail_end'] = ucwords( urldecode( get_search_query() ) );
			} else {
				$search_trail['trail_end'] = '<a href="' . get_search_link() . '" title="Search">Search</a>';
			}
			$trail = $search_trail;
		}

		return $trail;
	}


	/**
	 * Returns a search URI with the current filters.
	 * 
	 * If the $filter parameter is set with $value parameter in the current query, then it is 
	 * removed from the return URI, otherwise it is added.
	 * 
	 * @param $filter string The filter's rewrite slug. eg. post_type
	 * @param $value string The value for the filter eg. event
	 * 
	 * @author Brent Shepherd <brent@findingsimple.com>
	 * @package Simple Search
	 * @since 1.0
	 */
	public static function get_search_filter_uri( $filter, $value ) {
		global $wp_query;

		$search_link = ( empty( $wp_query->query_vars['s'] ) || $wp_query->query_vars['s'] == '~' ) ? get_search_link( '~' ) : get_search_link();
		$search_link = add_query_arg( $_GET, $search_link );

		$filter_query = '';


		if( isset( $wp_query->query_vars[$filter] ) ) { // Results already being filtered by this filter type

			$taxonomies = get_taxonomies( array( 'query_var' => $filter ) );

			// If we are filtering by a post_type, replace it with the new post type
			if( 'post_type' == $filter ) {
				$filter_query = ( $wp_query->query_vars[$filter] == $value ) ?  remove_query_arg( $filter, $search_link ) : add_query_arg( $filter, $value, $search_link );
			// If we are filtering by taxonomy
			} elseif( ! empty( $taxonomies ) ) {

				// Loop over the taxonomies being used to filter
				foreach( $wp_query->tax_query->queries as $query ) {

					$tax = get_taxonomy( $query['taxonomy'] );

					// If this taxonomy is not the filter in question, ignore it
					if( $tax->query_var != $filter )
						continue;

					// If the term for this URL is not already being used to filter the taxonomy
					if( strpos( $wp_query->query_vars[$filter], $value ) === false ) {
						// Append the term to the taxonomy filter string
						$filter_query = add_query_arg( $filter, $wp_query->query_vars[$filter] . '+' . $value, $search_link );
					} else {
						// Otherwise, remove the term
						if( $wp_query->query_vars[$filter] == $value ) {
							$filter_query = remove_query_arg( $filter, $search_link );
						} else {
							$filter_value = str_replace( $value, '', $wp_query->query_vars[$filter] );
							// Remove any residual + symbols left behind
							$filter_value = str_replace( '++', '+', $filter_value );
							$filter_value = preg_replace( '/(^\+|\+$)/', '', $filter_value );
							$filter_query = add_query_arg( $filter, $filter_value, $search_link );
						}
					}
				}
			}
		} else {
			$filter_query = add_query_arg( $filter, $value, $search_link );
		}

		return $filter_query;
	}


	/**
	 * When the tilde character is being used for search, the search SQL query searches for
	 * all posts containing an blank space, which is effectively everything.
	 * 
	 * @author Brent Shepherd <brent@findingsimple.com>
	 * @package Simple Search
	 * @since 1.0
	 */
	public static function search_all( $search_query, $query ) {
		global $wpdb;

		if ( $query->query_vars['s'] == '~' )
			$search_query = str_replace( '~', ' ', $search_query );
		elseif( ! is_user_logged_in() )
			$search_query = " AND ($wpdb->posts.post_password = '') ";
		else
			$search_query = ''; // We want everything that has a '_fss_relevance_{search_term}' meta key to account for content which has relevance based on shortcodes

		return $search_query;
	}


	/**
	 * Adds the search filter parameters to page links.
	 * 
	 * @author Brent Shepherd <brent@findingsimple.com>
	 * @package Simple Search
	 * @since 1.0
	 */
	public static function add_filters_to_page_links( $args ) {
		global $wp_query;

		/* Only modify search queries */
		if( ! $wp_query->is_search )
			return $args;

		$args['add_args'] = $wp_query->query;

		unset( $args['add_args']['s'] );
		unset( $args['add_args']['paged'] );

		return $args;
	}


	/**
	 * Returns a string detailing the number of search results and the result currently being displayed. eg. Showing 1-10 of 100 results
	 * 
	 * @author Brent Shepherd <brent@findingsimple.com>
	 * @package Simple Search
	 * @since 1.0
	 */
	public static function maybe_redirect_search() {

		if( ! is_admin() && isset( $_GET['s'] ) ) {

			if( empty( $_GET['s'] ) || $_GET['s'] == ' ' || $_GET['s'] == esc_attr( 'Search this site...', self::$text_domain ) )
				$_GET['s'] = '~';

			$search_link = get_search_link( trim( $_GET['s'] ) ); // It's too early for wp_query to be used, so force use of $_GET['s']

			unset( $_GET['s'] );
			unset( $_GET['submit'] );

			wp_safe_redirect( add_query_arg( $_GET, trailingslashit( $search_link ) ) );

			exit();
		}

	}


	/**
	 * Some browsers & WP have a few quirks for search queries, this function fixes them. 
	 * 
	 * First up, Firefox & Safari automatically escape ~ characters within URLs, so they send WordPress
	 * a URL of the form: www.example.com/search/%7E/ instead of www.example.com/search/~/. This function 
	 * unescapes any escaped tilde characters in the search query var.
	 * 
	 * WordPress also sets is_tax to true if any taxonomy filter is specified, so when filtering search
	 * results by a taxonomy, is_tax is set to true. When is_tax is set to true, WP also sets is_archive to
	 * true. Both of these are inaccurate when filtering search results, so this function sets them back to empty.
	 * 
	 * @author Brent Shepherd <brent@findingsimple.com>
	 * @package Simple Search
	 * @subpackage Events
	 * @since 1.0
	 */
	public static function fix_query( $query ) {

		if( isset( $query->query_vars['s'] ) && ! empty( $query->query_vars['s'] ) ) { // Search query

			if( $query->query_vars['s'] == urlencode( '~' ) )
				 $query->query_vars['s'] = '~';

			// Spaces have been sent encoded, but WP expects them unencoded
			$query->query_vars['s'] = str_replace( '+', ' ', $query->query_vars['s'] );

			if( $query->is_tax === true )
				 $query->is_tax = '';

			if( $query->is_archive === true )
				 $query->is_archive = '';
		}

		return $query;
	}


	/**
	 * If no search permastructure is set, then force WP to use "/search/xxxx" instead of the 
	 * default "?s=xxxx" to provide nicer looking urls and avoid infinite loops in the 
	 * self::maybe_redirect_search function.
	 * 
	 * @author Brent Shepherd <brent@findingsimple.com>
	 * @package Simple Search
	 * @since 1.0
	 */
	public static function search_link( $link, $search ) {
		global $wp_rewrite;

		$search_permastruct = $wp_rewrite->get_search_permastruct();

		if ( empty( $search_permastruct ) )
			$link = trailingslashit( home_url( 'search/' . urlencode( $search ) ) );

		return $link;
	}


	/**
	 * Removes shortcodes, HTML, PHP and punctuation from a string.
	 * 
	 * @author Brent Shepherd <brent@findingsimple.com>
	 * @package Simple Search
	 * @since 1.0
	 */
	public static function strip_string_bare( $string ) {

		$string = strip_shortcodes( $string );

		$string = strip_tags( $string );

		$string = self::remove_punctuation( $string );

		$string = self::strip_invisibles( $string );

		return $string;
	}


	/**
	 * Removes punctuation characters from a string. 
	 * 
	 * Based on the Relevanssi relevanssi_remove_punctuation function. 
	 * 
	 * @author Brent Shepherd <brent@findingsimple.com>
	 * @package Simple Search
	 * @since 1.0
	 */
	public static function remove_punctuation( $string ) {

		$string = strip_tags( $string );
		$string = stripslashes( $string );

		$string = str_replace( '&#8217;', '', $string );
		$string = str_replace( "'", '', $string );
		$string = str_replace( "Â´", '', $string );
		$string = str_replace( "â€™", '', $string );
		$string = str_replace( "â€˜", '', $string );
		$string = str_replace( "â€ž", '', $string );
		$string = str_replace( "Â·", '', $string );
		$string = str_replace( "â€", '', $string );
		$string = str_replace( "â€œ", '', $string );
		$string = str_replace( "â€¦", '', $string );
		$string = str_replace( "â‚¬", '', $string );
		$string = str_replace( "&shy;", '', $string );

		$string = str_replace( "â€”", ' ', $string );
		$string = str_replace( "â€“", ' ', $string );
		$string = str_replace( "Ã—", ' ', $string );
		$string = preg_replace( '/[[:punct:]]+/u', ' ', $string );

		$string = preg_replace( '/[[:space:]]+/', ' ', $string );
		$string = trim( $string );

		return $string;
	}


	/**
	 * Remove any invisible markup elements - script, style, iframe etc.. 
	 * 
	 * Based on the Relevanssi relevanssi_strip_invisibles function. 
	 * 
	 * @author Brent Shepherd <brent@findingsimple.com>
	 * @package Simple Search
	 * @since 1.0
	 */
	public static function strip_invisibles( $string ) {
		$string = preg_replace(
			array(
				'@<style[^>]*?>.*?</style>@siu',
				'@<script[^>]*?.*?</script>@siu',
				'@<object[^>]*?.*?</object>@siu',
				'@<embed[^>]*?.*?</embed>@siu',
				'@<applet[^>]*?.*?</applet>@siu',
				'@<noscript[^>]*?.*?</noscript>@siu',
				'@<noembed[^>]*?.*?</noembed>@siu',
				'@<iframe[^>]*?.*?</iframe>@siu',
				'@<del[^>]*?.*?</del>@siu',
				),
			' ',
			$string 
		);

		return $string;
	}


	/**
	 * Returns an array of words to filter out. Based on the Relevanssi stop word list.
	 * 
	 * @author Brent Shepherd <brent@findingsimple.com>
	 * @package Simple Search
	 * @since 1.0
	 */
	public static function get_stop_words() {
		return array(
			'a', 'about', 'above', 'above', 'across', 'after', 'afterwards', 'again', 'against', 'all', 'almost',
			'alone', 'along', 'already', 'also', 'although', 'always', 'am', 'among', 'amongst', 'amoungst', 'amount',
			'an', 'and', 'another', 'any', 'anyhow', 'anyone', 'anything', 'anyway', 'anywhere', 'are', 'around',
			'as',  'at', 'back', 'be', 'became', 'because', 'become', 'becomes', 'becoming', 'been', 'before',
			'beforehand', 'behind', 'being', 'below', 'beside', 'besides', 'between', 'beyond', 'bill', 'both', 'bottom',
			'but', 'by', 'call', 'can', 'cannot', 'cant', 'co', 'con', 'could', 'couldnt', 'cry',
			'de', 'describe', 'detail', 'do', 'done', 'down', 'due', 'during', 'each', 'eg', 'eight',
			'either', 'eleven', 'else', 'elsewhere', 'empty', 'enough', 'etc', 'even', 'ever', 'every', 'everyone',
			'everything', 'everywhere', 'except', 'few', 'fifteen', 'fify', 'fill', 'find', 'fire', 'first', 'five',
			'for', 'former', 'formerly', 'forty', 'found', 'four', 'from', 'front', 'full', 'further', 'get',
			'give', 'go', 'had', 'has', 'hasnt', 'have', 'he', 'hence', 'her', 'here', 'hereafter',
			'hereby', 'herein', 'hereupon', 'hers', 'herself', 'him', 'himself', 'his', 'how', 'however', 'hundred',
			'ie', 'if', 'in', 'inc', 'indeed', 'interest', 'into', 'is', 'it', 'its', 'itself',
			'keep', 'last', 'latter', 'latterly', 'least', 'less', 'ltd', 'made', 'many', 'may', 'me',
			'meanwhile', 'might', 'mill', 'mine', 'more', 'moreover', 'most', 'mostly', 'move', 'much', 'must',
			'my', 'myself', 'name', 'namely', 'neither', 'never', 'nevertheless', 'next', 'nine', 'no', 'nobody',
			'none', 'noone', 'nor', 'not', 'nothing', 'now', 'nowhere', 'of', 'off', 'often', 'on',
			'once', 'one', 'only', 'onto', 'or', 'other', 'others', 'otherwise', 'our', 'ours', 'ourselves',
			'out', 'over', 'own', 'part', 'per', 'perhaps', 'please', 'put', 'rather', 're', 'same',
			'see', 'seem', 'seemed', 'seeming', 'seems', 'serious', 'several', 'she', 'should', 'show', 'side',
			'since', 'sincere', 'six', 'sixty', 'so', 'some', 'somehow', 'someone', 'something', 'sometime', 'sometimes',
			'somewhere', 'still', 'such', 'system', 'take', 'ten', 'than', 'that', 'the', 'their', 'them',
			'themselves', 'then', 'thence', 'there', 'thereafter', 'thereby', 'therefore', 'therein', 'thereupon', 'these', 'they',
			'thickv', 'thin', 'third', 'this', 'those', 'though', 'three', 'through', 'throughout', 'thru', 'thus',
			'to', 'together', 'too', 'top', 'toward', 'towards', 'twelve', 'twenty', 'two', 'un', 'under',
			'until', 'up', 'upon', 'us', 'very', 'via', 'was', 'we', 'well', 'were', 'what',
			'whatever', 'when', 'whence', 'whenever', 'where', 'whereafter', 'whereas', 'whereby', 'wherein', 'whereupon', 'wherever',
			'whether', 'which', 'while', 'whither', 'who', 'whoever', 'whole', 'whom', 'whose', 'why', 'will',
			'with', 'within', 'without', 'would', 'yet', 'you', 'your', 'yours', 'yourself', 'yourselves', 'the'
		);
	}


	/**
	 * Replaces the title "Search results for '~'" with "Showing all search results".
	 * 
	 * @author Brent Shepherd <brent@findingsimple.com>
	 * @package Simple Search
	 * @since 1.0
	 */
	public static function seach_all_title( $doctitle ) {

		if( is_search() && $doctitle == __( 'Search results for &quot;~&quot;', self::$text_domain ) )
			$doctitle = __( 'Showing all search results', self::$text_domain );

		return $doctitle;
	}


	/**
	 * Sets the 'publicly_queryable' value of the page post type to true so that search results can be filtered by page. 
	 * 
	 * @author Brent Shepherd <brent@findingsimple.com>
	 * @package Simple Search
	 * @since 1.0
	 */
	public static function make_pages_queryable() {
		global $wp_post_types;

		$wp_post_types['page']->publicly_queryable = true;
	}


	/**
	 * When a post is saved, schedule a hook to index the posts relevance. This is not done when a post is updated
	 * to speed up post saving and also to save resources by not firing multiple times per minute if only small changes
	 * are being made.
	 * 
	 * @author Brent Shepherd <brent@findingsimple.com>
	 * @package Simple Search
	 * @since 1.0
	 */
	public static function schedule_relevance_index_building( $post_id, $post ) {

		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || $post->post_status == 'auto-draft' || wp_is_post_revision( $post ) )
			return;

		/* Re-index post relevance in two minutes (so it's up-to-date) and then again in a day and week (to account for impact of recenecy on relevance) */
		wp_clear_scheduled_hook( 'fss_build_post_relevance_index', array( 'post_id' => $post_id ) );
		wp_schedule_single_event( time() + 60 * 2, 'fss_build_post_relevance_index', array( 'post_id' => $post_id ) );

	}


	/**
	 * Cache scheduler
	 * 
	 * @author Brent Shepherd <brent@findingsimple.com>
	 * @package Simple Search
	 * @since 1.0
	 */
	public static function reschedule_relevance_index( $post_id ) {

		wp_clear_scheduled_hook( 'fss_build_post_relevance_index', array( 'post_id' => $post_id ) );

		$transient_key = 'fss_completed_relevance_indexes_' . $post_id;

		$completed_indexes = get_transient( $transient_key );
		delete_transient( $transient_key );

		if ( empty( $completed_indexes ) )
			$completed_indexes = 1;

		switch ( $completed_indexes ) {
			case 1 : // Reschedule for an hour
				$time = time() + 60 * 60;
				break;
			case 2 : // Reschedule for a day
				$time = time() + 60 * 60 * 24;
				break;
			case 3 : // Reschedule for a week
				$time = time() + 60 * 60 * 24 * 7;
				break;
			case 4 : // Reschedule for a fortnight
				$time = time() + 60 * 60 * 24 * 7 * 2;
				break;
			case 5 : // Reschedule for a month
				$time = time() + 60 * 60 * 24 * 30;
				break;
			default : // Don't reschedule
				$time = false;
				break;
		}

		if ( $time !== false ) {
			wp_schedule_single_event( $time, 'fss_build_post_relevance_index', array( 'post_id' => $post_id ) );
			set_transient( $transient_key, $completed_indexes + 1, $time + 60 * 60 * 24 );
		}

	}


	/**
	 * When a post is saved or index is rebuilt, calculate and store a posts relevance score 
	 * for previous search terms.
	 *
	 * @author Brent Shepherd <brent@findingsimple.com>
	 * @package Simple Search
	 * @since 1.0
	 */
	public static function build_post_relevance_index( $post_id ) {
		global $post, $wpdb;

		$post = get_post( $post_id ); // Also sets the global $post var for functions hooked to 'the_content' which expect to be run in the loop

		/* Cache for all existing searches */
		$search_query_keys = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s", self::$tally_prefix . '%' ) );

		foreach( $search_query_keys as $search_query_key ) {
			unset( $post->relevance );
			$search_query = self::get_search_query_from_key( $search_query_key );
			self::calculate_relevance_for_post( $post, $search_query );
		}
	}


	/**
	 * Scheduled to run once a month to clean search index.
	 *
	 * @author Brent Shepherd <brent@findingsimple.com>
	 * @package Simple Search
	 * @since 1.0
	 */
	public static function clean_search_index() {
		global $wpdb;

		// Get all search queries with tally < 1
		$option_names_to_delete = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s AND option_value < 2", self::$tally_prefix . '%' ) );

		if ( empty( $option_names_to_delete ) )
			return;

		$relevance_keys_to_delete = array();

		foreach( $option_names_to_delete as $option_name ) {
			$search_query = self::get_search_query_from_key( $option_name );
			$relevance_keys_to_delete[] = self::get_search_query_key( $search_query, self::$relevance_prefix );
		}

		// Delete the tallies
		$tally_rows_deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->options WHERE option_name IN ( '" . implode( "', '", $wpdb->escape( $option_names_to_delete ) ) . "' )" ) );

		// Delete the relevance scores
		$relevance_rows_deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->postmeta WHERE meta_key IN ( '" . implode( "', '", $wpdb->escape( $relevance_keys_to_delete ) ) . "' )" ) );
	}


	/**
	 * Returns a DB safe key from a given search query by replaces spaces with _.
	 * 
	 * @author Brent Shepherd <brent@findingsimple.com>
	 * @package Simple Search
	 * @since 1.0
	 */
	public static function get_search_query_key( $search_query, $prefix ) {

		$search_query_metakey = self::strip_string_bare( $search_query );
		$search_query_metakey = str_replace( ' ', '_', preg_replace( '/\s{2,}/', ' ', strtolower( $search_query_metakey ) ) );

		return $prefix . $search_query_metakey;
	}


	/**
	 * Returns the search query from DB meta key.
	 * 
	 * @author Brent Shepherd <brent@findingsimple.com>
	 * @package Simple Search
	 * @since 1.0
	 */
	public static function get_search_query_from_key( $search_query_key ) {

		$search_query = str_replace( self::$relevance_prefix, '', strtolower( $search_query_key ) );
		$search_query = str_replace( self::$tally_prefix, '', strtolower( $search_query_key ) );
		$search_query = str_replace( '_', ' ', $search_query );

		return $search_query;
	}

	/**
	 * Adds a "monthly" cron schedule to teh 
	 * 
	 * @author Brent Shepherd <brent@findingsimple.com>
	 * @package Simple Search
	 * @since 1.0
	 */
	public static function add_custom_cron_schedules( $schedules ) {

		$schedules['monthly'] = array(
			'interval' => 60 * 60 * 24 * 30, // roughly one month in seconds
			'display'  => __( 'Once Monthly' ),
		);

		return $schedules;
	}
}

endif;
