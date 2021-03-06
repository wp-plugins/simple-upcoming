<?php
/*
 * Plugin Name: Simple upcoming
 * Description: Assign an event date to any post.  List your upcoming events using the shortcode [upcoming].
 * Version: 0.3
 * Author: Samuel Coskey, Victoria Gitman
 * Author URI: http://boolesrings.org
*/

/*
 * Setting the EventDate in the post editor
 * We need to add a meta_box as well as the code to save
 * the user's input.
*/
add_action( 'add_meta_boxes', 'upcoming_add_eventdate_box' );
function upcoming_add_eventdate_box() {
	add_meta_box( 'eventdate', 'Event Date', 'upcoming_eventdate_box', 'post', 'side' );
}
function upcoming_eventdate_box( $post ) {
	echo '<p>If this post corresponds to an upcoming event, enter the date of that event here.  Use any format recognized by the php <a href="http://php.net/manual/en/function.strtotime.php">strtotime</a> function; it will be converted to your the default date format specified in your general settings.</p>';
	wp_nonce_field( plugin_basename( __FILE__ ), 'upcoming_noncename' );
	echo '<label for="upcoming_date">';
	echo 'Event Date';
	echo '</label>' . "\n";
	echo '<input type="text" id="eventdate" name="eventdate" size="20" value="';
	$the_event_date = get_post_meta($post->ID, 'EventDate', true);
	if ( $the_event_date ) {
		echo date( get_option('date_format'), strtotime($the_event_date) );
	}
	echo '" />' . "\n";
}
add_action( 'save_post', 'upcoming_save_eventdate' );
function upcoming_save_eventdate( $post_id ) {
	// verify if this is an auto save routine.
	// If it is our form has not been submitted, so we dont want to do anything
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	// verify this came from the our screen and with proper authorization,
	// because save_post can be triggered at other times
	if ( !wp_verify_nonce( $_POST['upcoming_noncename'], plugin_basename( __FILE__ ) ) ) {
		return;
	}
	// Check permissions
	if ( !current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	// OK, we're authenticated: we need to find and save the data
	$new_date = $_POST['eventdate'];
	if ( $new_date ) {
		// if the user types 'today' this will ensure the timezone is properly understood
		date_default_timezone_set(get_option('timezone_string'));
		$new_date = date( "Ymd", strtotime( $new_date ) );
		update_post_meta( $post_id, 'EventDate', $new_date );
	} else {
		delete_post_meta( $post_id, 'EventDate' );
	}
}

/*
 * The shortcode which shows a list of future events.
 * Usage example:
 * [upcoming category_name="talks" show_excerpt="yeah"]
*/
add_shortcode( 'upcoming', 'upcoming_loop' );

function upcoming_loop( $atts ) {
	global $more;
	global $post;

	// Arguments to the shortcode
	extract( shortcode_atts(  array(
        	'category_name' => '',
		'days_old' => 0,
		'style' => 'list',
		'text' => 'none',
		'null_text' => '(none)',
		'class_name' => '',
		'show_date' => '',
		'date_format' => get_option('date_format'), // I recommend 'F j, Y'
		'q' => '',
	), $atts ) );

	/*
	 * sanitize the input a little bit
	*/
	if ( $style != "list" && $style != "post" ) {
		$style = "list";
	}
	if ( $text != "none" && $text != "excerpt" && $text != "normal" ) {
		$text = "none";
	}
	if ( $q ) {
		$q = str_replace ( "&#038;", "&", $q );
	}

	/*
	 * query the database for the posts with EventDate in the future
	 * query syntax: http://codex.wordpress.org/Class_Reference/WP_Query#Parameters
	*/
	$query = "";
	if ( $category_name ) {
		$query .= "category_name=" . $category_name . '&';
	}
	$query .= 'meta_key=EventDate&orderby=meta_value&order=ASC&ignore_sticky_posts=1&posts_per_page=-1';
	if ( $q ) {
		$query .= "&" . $q;
	}
	$where_future = function ( $where ) use( $days_old ) {
		return $where . "AND STR_TO_DATE(meta_value,'%Y%m%d') >= CURDATE()"
			      . " - INTERVAL $days_old DAY";
	};
	if ( is_numeric( $days_old ) ) {
		add_filter( 'posts_where', $where_future );
	}
	$query_results = new WP_Query($query);
	remove_filter( 'posts_where', $where_future );

	if ( $query_results->post_count == 0 ) {
		return "<p>" . wp_kses($null_text,array()) . "</p>\n";
	}
	
	/*
	 * building the output
	*/
	$ret_val = "<ul class='upcoming upcoming-$style";
	if ( $class_name ) {
		$ret_val .= " " . $class_name;
	}
	$ret_val .= "'>\n";
	while ( $query_results->have_posts() ) {
		$query_results->the_post();
		$ret_val .= "<li class='";
		foreach((get_the_category()) as $category) {
			$ret_val .= "category-" . $category->slug . " ";
		}
		$ret_val .= "'>";
		if ( $style == "post" ) {
			$ret_val .= "<h2 class='upcoming-entry-title'>";
		}
		if ( $show_date ) {
			$ret_val .= "<span class='upcoming_date'>";
			$ret_val .= date($date_format,
					strtotime(get_post_meta($post->ID, 'EventDate',true)));
			$ret_val .= "</span>";
			$ret_val .= "<span class='upcoming_date_sep'>: </span>\n";
		}
		$ret_val .= "<span class='upcoming_title'><a href='" . get_permalink() . "'>";
		$ret_val .= the_title( '', '', false);
		$ret_val .= "</a></span>";
		if ( $style == "post" ) {
			$ret_val .= "</h2>";
		}
		$ret_val .= "\n";
		if ( $text == "excerpt" ) {
			$ret_val .= "<div>\n";
			$ret_val .= get_the_excerpt();
			$ret_val .= "</div>\n";			
		} elseif ( $text == "normal" ) {
			$ret_val .= "<div>\n";
			$more = 0; // Tell wordpress to respect the [more] tag for the next line:
			$ret_val .= apply_filters( 'the_content', get_the_content("") );
			$ret_val .= "</div>\n";
		}
		$ret_val .= "</li>";
	}
	wp_reset_postdata();
	$ret_val .= "</ul>";

	return $ret_val;
}


/*
 * Load our default style sheet
*/
add_action( 'wp_print_styles', 'enqueue_my_styles' );
function enqueue_my_styles() {
	wp_register_style( 'simple-upcoming-styles',
			plugins_url('simple-upcoming-styles.css', __FILE__) );
	wp_enqueue_style( 'simple-upcoming-styles' );
}

?>