<?php
/*
Plugin Name: Pinecone Indexer V3
Plugin URI: https://dissentwatch.com
Description: Indexes WordPress posts into Pinecone for chatbot context
Version: 3.0
Author: Reza Consulting Inc.
Author URI: https://reza.consulting
*/

// Configuration constants (define in wp-config.php or here)
/*
define('PINECONE_API_KEY', ''); //from your pinecone.io account
define('PINECONE_ENV', ''); // e.g., "us-west1-gcp"
define('PINECONE_INDEX_NAME', ''); // the name of the index you created on your pinecone.io account
define('HF_API_TOKEN', ''); // your huggingface api token
define('HF_API_URL', ''); // your huggingface api url
define('FORCE_RESET', false); // Set to true to force reset _pinecone_indexed metadata on activation/deactivation
define('PINECONE_API_HOST', ''); // your pinecone.io api host url
*/

// Pinecone custom host from dashboard
define('PINECONE_API_HOST_V3', 'https://dissentbot-custom-384-v3-rghdik0.svc.aped-4627-b74a.pinecone.io');

// Log configuration
define('PLUGIN_LOG_DIR_V3', plugin_dir_path(__FILE__) . 'logs/');
define('PLUGIN_LOG_PATH_V3', PLUGIN_LOG_DIR_V3 . 'error.log');
define('LOG_MAX_AGE_DAYS_V3', 30);

// Ensure log directory exists with proper permissions
if (!is_dir(PLUGIN_LOG_DIR_V3)) {
    mkdir(PLUGIN_LOG_DIR_V3, 0775, true);
}

// Create .htaccess to block direct access
/*
$htaccess_path = PLUGIN_LOG_DIR_V3 . '.htaccess';
if (!file_exists($htaccess_path)) {
    file_put_contents($htaccess_path, "deny from all");
    chmod($htaccess_path, 0644);
}
*/

// Logging function with multiple destinations
function plugin_log_v3($message) {
    $timestamp = date('[Y-m-d H:i:s]');
    $log_entry = "$timestamp ERROR: $message\n";
    
    // Write to plugin log
    //error_log($log_entry, 3, PLUGIN_LOG_PATH_V3);
    
    // Write to PHP error log (fallback)
    error_log($log_entry);
    
    // Write to WordPress debug.log if enabled
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log($log_entry, 3, WP_CONTENT_DIR . '/debug.log');
    }
}

// Pinecone client class with full error handling
class PineconeClientV3 {
    private $api_key;
    private $api_host;
    private $index_name;

    public function __construct() {
        $this->api_key = PINECONE_API_KEY_V3;
        $this->api_host = PINECONE_API_HOST_V3;
        $this->index_name = PINECONE_INDEX_NAME_V3;
    }

    public function upsertVectors(array $vectors) {
        $url = "$this->api_host/vectors/upsert";
        $data = ['vectors' => $vectors];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'api-key: ' . $this->api_key
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        // Log detailed API response
        plugin_log_v3("Pinecone API Response:");
        plugin_log_v3("  URL: $url");
        plugin_log_v3("  Status: $http_code");
        plugin_log_v3("  Response Body: " . ($response ?: 'Empty'));
        plugin_log_v3("  cURL Error: $curl_error");

        if ($curl_error) {
            plugin_log_v3("cURL Error: $curl_error");
        }

        if ($http_code !== 200) {
            plugin_log_v3("HTTP Error Code: $http_code");
        }

        return $http_code === 200;
    }
}

// Hugging Face embedding function with detailed error handling
function getEmbedding_v3($text) {
    $url = HF_API_URL_V3; // Correct Inference API endpoint with feature-extraction task
    $headers = [
        'Authorization: Bearer ' . HF_API_TOKEN_V3,
        'Content-Type: application/json'
    ];

    // Prepare the input data according to the model's requirements
    $inputData = json_encode([
        'inputs' => [ 
            'sentences' => [$text]
        ]
    ]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $inputData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    // Logging
    plugin_log_v3("Hugging Face API Response for text: $text");
    plugin_log_v3("  Status: $http_code");
    //plugin_log_v3("  Response Body: " . ($response ?: 'Empty'));
    plugin_log_v3("  cURL Error: $curl_error");

    if ($curl_error) {
        plugin_log_v3("cURL Error: $curl_error");
    }

    if ($http_code !== 200) {
        plugin_log_v3("HTTP Error Code: $http_code");
        return false;
    }

    $responseData = json_decode($response, true);
    if (!isset($responseData[0]) || !is_array($responseData[0]) || count($responseData[0]) != 384) {
        plugin_log_v3("Invalid response from Hugging Face API");
        return false;
    }

    return $responseData[0]; // Return the 384D vector
}

// Function to reset _pinecone_indexed metadata
function resetPineconeIndexed_v3() {
    global $wpdb;
    $table_posts = $wpdb->prefix . 'postmeta';

    // Delete all rows with meta_key = '_pinecone_indexed_v3'
    $result = $wpdb->delete(
        $table_posts,
        [
            'meta_key' => '_pinecone_indexed_v3'
        ]
    );

    plugin_log_v3("Reset _pinecone_indexed_v3 metadata. Rows affected: $result");
	
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = 'dw_needs_pinecone'" );
	$wpdb->query(
		"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
		SELECT ID, 'dw_needs_pinecone', '1' FROM {$wpdb->posts}
		WHERE post_type = 'post' AND post_status = 'publish'"
	);
}

// Cron job setup with log rotation
register_activation_hook(__FILE__, function() {
    // Clear existing cron jobs
    wp_clear_scheduled_hook('pinecone_index_cron_v3');
    wp_clear_scheduled_hook('pinecone_log_rotation_v3');

    // Schedule main cron job every minute
	plugin_log_v3("Checking if a cron is already scheduled ...");
	$wp_next_scheduled_response = wp_next_scheduled('pinecone_index_cron_v3');
    if (!$wp_next_scheduled_response) {
		plugin_log_v3("... nope. Scheduling one now ...");
        wp_schedule_event($time = time(), 'hourly', 'pinecone_index_cron_v3', array(),true);
		plugin_log_v3("... done scheduling cron for " . date('m/d/Y H:i:s', $time));
    } else {
		plugin_log_v3("... cron already scheduled. Not scheduling a new one this time. wp_next_scheduled_response date/time: " . date('m/d/Y H:i:s', $wp_next_scheduled_response));
	}

    // Schedule log rotation daily
    if (!wp_next_scheduled('pinecone_log_rotation_v3')) {
        wp_schedule_event(time(), 'daily', 'pinecone_log_rotation_v3');
    }

    // Reset _pinecone_indexed_v3 metadata if FORCE_RESET_V3 is true
    if (defined('FORCE_RESET_V3') && FORCE_RESET_V3) {
        resetPineconeIndexed_v3();
    }

    plugin_log_v3("Plugin activated. Cron jobs scheduled.");
    check_cron_events_v3();
});

register_deactivation_hook(__FILE__, function() {
    // Clear scheduled cron jobs
    wp_clear_scheduled_hook('pinecone_index_cron_v3');
    wp_clear_scheduled_hook('pinecone_log_rotation_v3');

    // Reset _pinecone_indexed_v3 metadata if FORCE_RESET_V3 is true
    if (defined('FORCE_RESET_V3') && FORCE_RESET_V3) {
        resetPineconeIndexed_v3();
    }

    plugin_log_v3("Plugin deactivated. Cron jobs cleared.");
});

// Add custom cron interval (every minute)
add_filter('cron_schedules', function($schedules) {
    $schedules['everyminute'] = [
        'interval' => 60,
        'display' => 'Every Minute'
    ];
    return $schedules;
});

// Log rotation cron job
add_action('pinecone_log_rotation_v3', function() {
    $max_age = LOG_MAX_AGE_DAYS_V3 * 24 * 60 * 60;
    if (file_exists(PLUGIN_LOG_PATH_V3) && (time() - filemtime(PLUGIN_LOG_PATH_V3)) > $max_age) {
        $new_filename = PLUGIN_LOG_DIR_V3 . 'error_' . date('Y-m-d') . '.log';
        rename(PLUGIN_LOG_PATH_V3, $new_filename);
        touch(PLUGIN_LOG_PATH_V3);
        plugin_log_v3("Log rotated to: $new_filename");
    }
});

// Main processing function
add_action('pinecone_index_cron_v3', function() {
    timer_start(); // Start timing the execution

    plugin_log_v3("Cron job triggered at: " . current_time('mysql'));

    // Validate Pinecone configuration
    if (!defined('PINECONE_API_KEY_V3') || empty(PINECONE_API_KEY_V3)) {
        plugin_log_v3("Pinecone API key missing!");
        return;
    }

    if (!defined('PINECONE_API_HOST_V3') || empty(PINECONE_API_HOST_V3)) {
        plugin_log_v3("Pinecone host URL missing!");
        return;
    }

    // Fetch oldest unprocessed post
    $last_id = (int) get_option('pinecone_last_processed_id_v3', 0);
    $args = [
        'posts_per_page' => 250,
        'post_status' => 'publish',
        'orderby' => 'ID',
        'order' => 'ASC',
        'post_type' => 'post',
		'meta_query' => [['key' => 'dw_needs_pinecone', 'compare' => 'EXISTS']]
        //'meta_query' => [['key' => '_pinecone_indexed_v3', 'compare' => 'NOT EXISTS']]
    ];

    if ($last_id > 0) {
        //$args['post__not_in'] = [$last_id];
    }

    $posts = get_posts($args);
    if (empty($posts)) {
        plugin_log_v3("No more posts to process");
        update_option('pinecone_last_processed_id_v3', 0);
        return;
    }

    //$post = $posts[0];
	foreach ($posts as $post){	
		$title = html_entity_decode(sanitize_text_field($post->post_title));
		
		$author_id = $post->post_author;
		plugin_log_v3("author_id: " . $author_id);
		$author_name = get_the_author_meta( 'display_name', $author_id);
		plugin_log_v3("author_name: " . $author_name);
		
		$categories = wp_list_pluck(get_the_category($post->ID), 'name');

		// Sanitize categories
		$sanitized_categories = array_map('sanitize_text_field', $categories);
		$categories_str = implode(', ', $sanitized_categories);
		
		$raw_content = 
		"Title: " . $title . "\n" .
		"Date: " . $post->post_date . "\n" .
		"Author: " . $author_name . "\n" .
		"Content: " .  sanitize_text_field(html_entity_decode(wp_strip_all_tags($post->post_content))) . "\n" . // Clean HTML tags and sanitize content 
		"Categories: " . $categories_str;
		
		$content = str_replace("Powered by WPeMatico","",$raw_content); //remove WPematico message


		// Log post details
		plugin_log_v3("Processing post ID: " . $post->ID);
		plugin_log_v3("  Title: " . $title);
		plugin_log_v3("  Raw Content length: " . strlen($raw_content));
		plugin_log_v3("  Raw Content: " . substr($raw_content, 0, 100) . '...');
		plugin_log_v3("  Sanitized Content length: " . strlen($content));
		plugin_log_v3("  Sanitized Content: " . substr($content, 0, 100) . '...');

		// Check if post is already indexed
		$is_indexed = get_post_meta($post->ID, '_pinecone_indexed_v3', true);
		if ($is_indexed) {
			plugin_log_v3("Post ID: " . $post->ID . " is already indexed. Skipping.");
			update_option('pinecone_last_processed_id_v3', $post->ID);
			delete_post_meta($post->ID, 'dw_needs_pinecone');
			continue;
		}

		// Generate embedding
		$embedding = getEmbedding_v3($content);
		if (!$embedding) {
			plugin_log_v3("Embedding failed for post ID: " . $post->ID);
			continue;
		}

		// Log embedding dimensions
		$vector_length = is_array($embedding) ? count($embedding) : 0;
		plugin_log_v3("Embedding generated successfully - dimensions: $vector_length");
		
		$nostr_event_id = get_post_meta($post->ID, 'nostr_event_id', true);
		

		// Prepare metadata
		/*
		$metadata = [
			'title' => $title,
			'content' => sanitize_text_field(substr($content, 0, 100) . '...'),
			'categories' => $categories_str,
			'date' => sanitize_text_field($post->post_date),
			'source_url' => esc_url(get_post_meta($post->ID, 'link', true)),
			'archive_url' => esc_url(get_post_meta($post->ID, 'archive_link', true)),
			'author' => $author_name,
		];
		*/
		
		$datetime_array = explode(" ", $post->post_date);
		
		$metadata = [];
		$metadata['post_id'] = $post->ID;
		
		if ($title) $metadata['title'] = $title;
		//$metadata['content'] = sanitize_text_field(substr(str_replace("Powered by WPeMatico","",html_entity_decode(wp_strip_all_tags($post->post_content))), 0, 2500)) . '...';
		$metadata['content'] = sanitize_text_field(mb_substr(str_replace("Powered by WPeMatico","",html_entity_decode(wp_strip_all_tags($post->post_content))), 0, 2500)) . '...';
		if ($categories_str) $metadata['categories'] = $categories_str;
		if ($date = $datetime_array[0]) $metadata['date'] = $datetime_array[0];
		if ($time = $datetime_array[1]) $metadata['time'] = $datetime_array[1];
		if ($timestamp = get_post_timestamp($post->ID)) $metadata['timestamp'] = (int)$timestamp;
		if ($source_url = esc_url(get_post_meta($post->ID, 'link', true))) $metadata['source_url'] = $source_url;
		if ($archive_url = esc_url(get_post_meta($post->ID, 'archive_link', true))) $metadata['archive_url'] = $archive_url;
		if ($author_name) $metadata['author'] = $author_name;
		if ($nostr_event_id) $metadata['nostr_event_id'] = $nostr_event_id;

		// Log detailed metadata
		plugin_log_v3("Detailed metadata being sent to Pinecone: " . print_r($metadata, true));

		// Upsert to Pinecone
		$pinecone = new PineconeClientV3();
		$pinecone_data = [
			[
				'id' => 'post_' . $post->ID,
				'values' => $embedding,
				'metadata' => $metadata
			]
		];

		//plugin_log_v3("Array of data sent to Pinecone: " . print_r($pinecone_data, true));

		if (!$pinecone->upsertVectors($pinecone_data)) {
			plugin_log_v3("Pinecone upsert failed for post ID: " . $post->ID);
			continue;
		}

		// Update tracking
		update_option('pinecone_last_processed_id_v3', $post->ID);
		$update_response = update_post_meta($post->ID, '_pinecone_indexed_v3', true);
		plugin_log_v3("update_post_meta function to mark post as processed response: " . $update_response);
		plugin_log_v3("Successfully processed post ID: " . $post->ID);
	}
	
	// Log memory usage and execution time
	$memory_usage = memory_get_usage();
	$peak_memory_usage = memory_get_peak_usage();
	$execution_time = timer_stop();

	plugin_log_v3("Memory Usage: " . round($memory_usage / 1024 / 1024, 2) . " MB");
	plugin_log_v3("Peak Memory Usage: " . round($peak_memory_usage / 1024 / 1024, 2) . " MB");
	plugin_log_v3("Execution Time: " . $execution_time . " seconds");
	
});

// Function to check and log cron events
function check_cron_events_v3() {
    $events = _get_cron_array();
    $cron_events = [];

    foreach ($events as $timestamp => $cron) {
        foreach ($cron as $hook => $args) {
            $cron_events[$hook][] = $timestamp;
        }
    }

    plugin_log_v3("Scheduled Cron Events:");
    plugin_log_v3(print_r($cron_events, true));
}

// Run cron event check on plugin activation
register_activation_hook(__FILE__, 'check_cron_events_v3');

// Additional debugging: Check if cron job is being triggered
add_action('init', function() {
    if (isset($_GET['trigger_cron'])) {
        do_action('pinecone_index_cron_v3');
        plugin_log_v3("Manually triggered pinecone_index_cron_v3 via URL parameter.");
    }
});

add_action( 'transition_post_status', function ( $new_status, $old_status, $post ) {
	if ( 'publish' === $new_status && 'post' === $post->post_type
		&& ! metadata_exists( 'post', $post->ID, '_pinecone_indexed_v3' ) ) {
		update_post_meta( $post->ID, 'dw_needs_pinecone', '1' );
	}
}, 10, 3 );

function dw_sync_pinecone_flag( $meta_id, $post_id, $meta_key ) {
	if ( '_pinecone_indexed_v3' === $meta_key ) {
		delete_post_meta( $post_id, 'dw_needs_pinecone' );
	}
}
add_action( 'added_post_meta', 'dw_sync_pinecone_flag', 10, 3 );
add_action( 'updated_post_meta', 'dw_sync_pinecone_flag', 10, 3 );

add_action( 'deleted_post_meta', function ( $meta_ids, $post_id, $meta_key ) {
	if ( '_pinecone_indexed_v3' === $meta_key && 'publish' === get_post_status( $post_id ) ) {
		update_post_meta( $post_id, 'dw_needs_pinecone', '1' );
	}
}, 10, 3 );
