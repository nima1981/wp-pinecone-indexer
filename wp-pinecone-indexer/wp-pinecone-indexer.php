<?php
/*
Plugin Name: WP Pinecone Indexer
Plugin URI: https://reza.consulting
Description: Indexes WordPress posts into Pinecone for chatbot context (built for DissentWatch.com - customize data to your needs.)
Version: 3.0
Author: Reza Consuting Inc.
Author URI: https://reza.consulting
*/

// Configuration constants (define in wp-config.php or uncomment and enter here:)

/*
define('PINECONE_API_KEY', ''); //from your pinecone.io account
define('PINECONE_ENV', ''); // e.g., "us-west1-gcp"
define('PINECONE_INDEX_NAME', ''); // the name of the index you created on your pinecone.io account
define('HF_API_TOKEN', ''); // your huggingface api token
define('HF_API_URL', ''); // your huggingface api url
define('FORCE_RESET', false); // Set to true to force reset _pinecone_indexed metadata on activation/deactivation
define('PINECONE_API_HOST', ''); // your pinecone.io api host url
*/

// Log configuration
define('PLUGIN_LOG_DIR', plugin_dir_path(__FILE__) . 'logs/');
define('PLUGIN_LOG_PATH', PLUGIN_LOG_DIR . 'error.log');
define('LOG_MAX_AGE_DAYS', 30);

// Ensure log directory exists with proper permissions
if (!is_dir(PLUGIN_LOG_DIR)) {
    mkdir(PLUGIN_LOG_DIR, 0775, true);
}

// Create .htaccess to block direct access
/*
$htaccess_path = PLUGIN_LOG_DIR . '.htaccess';
if (!file_exists($htaccess_path)) {
    file_put_contents($htaccess_path, "deny from all");
    chmod($htaccess_path, 0644);
}
*/

// Logging function with multiple destinations
function plugin_log($message) {
    $timestamp = date('[Y-m-d H:i:s]');
    $log_entry = "$timestamp ERROR: $message\n";
    
    // Write to plugin log
    //error_log($log_entry, 3, PLUGIN_LOG_PATH);
    
    // Write to PHP error log (fallback)
    error_log($log_entry);
    
    // Write to WordPress debug.log if enabled
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log($log_entry, 3, WP_CONTENT_DIR . '/debug.log');
    }
}

// Pinecone client class with full error handling
class PineconeClient {
    private $api_key;
    private $api_host;
    private $index_name;

    public function __construct() {
        $this->api_key = PINECONE_API_KEY;
        $this->api_host = PINECONE_API_HOST;
        $this->index_name = PINECONE_INDEX_NAME;
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
        plugin_log("Pinecone API Response:");
        plugin_log("  URL: $url");
        plugin_log("  Status: $http_code");
        plugin_log("  Response Body: " . ($response ?: 'Empty'));
        plugin_log("  cURL Error: $curl_error");

        if ($curl_error) {
            plugin_log("cURL Error: $curl_error");
        }

        if ($http_code !== 200) {
            plugin_log("HTTP Error Code: $http_code");
        }

        return $http_code === 200;
    }
}

// Hugging Face embedding function with detailed error handling
function getEmbedding($text) {
    $url = HF_API_URL; // Correct Inference API endpoint with feature-extraction task
    $headers = [
        'Authorization: Bearer ' . HF_API_TOKEN,
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
    plugin_log("Hugging Face API Response for text: $text");
    plugin_log("  Status: $http_code");
    //plugin_log("  Response Body: " . ($response ?: 'Empty'));
    plugin_log("  cURL Error: $curl_error");

    if ($curl_error) {
        plugin_log("cURL Error: $curl_error");
    }

    if ($http_code !== 200) {
        plugin_log("HTTP Error Code: $http_code");
        return false;
    }

    $responseData = json_decode($response, true);
    if (!isset($responseData[0]) || !is_array($responseData[0]) || count($responseData[0]) != 384) {
        plugin_log("Invalid response from Hugging Face API");
        return false;
    }

    return $responseData[0]; // Return the 384D vector
}

// Function to reset _pinecone_indexed metadata
function resetPineconeIndexed() {
    global $wpdb;
    $table_posts = $wpdb->prefix . 'postmeta';

    // Delete all rows with meta_key = '_pinecone_indexed'
    $result = $wpdb->delete(
        $table_posts,
        [
            'meta_key' => '_pinecone_indexed'
        ]
    );

    plugin_log("Reset _pinecone_indexed metadata. Rows affected: $result");
}

// Cron job setup with log rotation
register_activation_hook(__FILE__, function() {
    // Clear existing cron jobs
    wp_clear_scheduled_hook('pinecone_index_cron');
    wp_clear_scheduled_hook('pinecone_log_rotation');

    // Schedule main cron job every minute
	plugin_log("Checking if a cron is already scheduled ...");
	$wp_next_scheduled_response = wp_next_scheduled('pinecone_index_cron');
    if (!$wp_next_scheduled_response) {
		plugin_log("... nope. Scheduling one now ...");
        wp_schedule_event($time = time(), 'hourly', 'pinecone_index_cron', array(),true);
		plugin_log("... done scheduling cron for " . date('m/d/Y H:i:s', $time));
    } else {
		plugin_log("... cron already scheduled. Not scheduling a new one this time. wp_next_scheduled_response date/time: " . date('m/d/Y H:i:s', $wp_next_scheduled_response));
	}

    // Schedule log rotation daily
    if (!wp_next_scheduled('pinecone_log_rotation')) {
        wp_schedule_event(time(), 'daily', 'pinecone_log_rotation');
    }

    // Reset _pinecone_indexed metadata if FORCE_RESET is true
    if (defined('FORCE_RESET') && FORCE_RESET) {
        resetPineconeIndexed();
    }

    plugin_log("Plugin activated. Cron jobs scheduled.");
    check_cron_events();
});

register_deactivation_hook(__FILE__, function() {
    // Clear scheduled cron jobs
    wp_clear_scheduled_hook('pinecone_index_cron');
    wp_clear_scheduled_hook('pinecone_log_rotation');

    // Reset _pinecone_indexed metadata if FORCE_RESET is true
    if (defined('FORCE_RESET') && FORCE_RESET) {
        resetPineconeIndexed();
    }

    plugin_log("Plugin deactivated. Cron jobs cleared.");
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
add_action('pinecone_log_rotation', function() {
    $max_age = LOG_MAX_AGE_DAYS * 24 * 60 * 60;
    if (file_exists(PLUGIN_LOG_PATH) && (time() - filemtime(PLUGIN_LOG_PATH)) > $max_age) {
        $new_filename = PLUGIN_LOG_DIR . 'error_' . date('Y-m-d') . '.log';
        rename(PLUGIN_LOG_PATH, $new_filename);
        touch(PLUGIN_LOG_PATH);
        plugin_log("Log rotated to: $new_filename");
    }
});

// Main processing function
add_action('pinecone_index_cron', function() {
    timer_start(); // Start timing the execution

    plugin_log("Cron job triggered at: " . current_time('mysql'));

    // Validate Pinecone configuration
    if (!defined('PINECONE_API_KEY') || empty(PINECONE_API_KEY)) {
        plugin_log("Pinecone API key missing!");
        return;
    }

    if (!defined('PINECONE_API_HOST') || empty(PINECONE_API_HOST)) {
        plugin_log("Pinecone host URL missing!");
        return;
    }

    // Fetch oldest unprocessed post
    $last_id = (int) get_option('pinecone_last_processed_id', 0);
    $args = [
        'posts_per_page' => 250,
        'post_status' => 'publish',
        'orderby' => 'ID',
        'order' => 'ASC',
        'post_type' => 'post',
        'meta_query' => [['key' => '_pinecone_indexed', 'compare' => 'NOT EXISTS']]
    ];

    if ($last_id > 0) {
        //$args['post__not_in'] = [$last_id];
    }

    $posts = get_posts($args);
    if (empty($posts)) {
        plugin_log("No more posts to process");
        update_option('pinecone_last_processed_id', 0);
        return;
    }

    //$post = $posts[0];
	foreach ($posts as $post){	
		$title = html_entity_decode(sanitize_text_field($post->post_title));
		
		$author_id = $post->post_author;
		plugin_log("author_id: " . $author_id);
		$author_name = get_the_author_meta( 'display_name', $author_id);
		plugin_log("author_name: " . $author_name);
		
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
		plugin_log("Processing post ID: " . $post->ID);
		plugin_log("  Title: " . $title);
		plugin_log("  Raw Content length: " . strlen($raw_content));
		plugin_log("  Raw Content: " . substr($raw_content, 0, 100) . '...');
		plugin_log("  Sanitized Content length: " . strlen($content));
		plugin_log("  Sanitized Content: " . substr($content, 0, 100) . '...');

		// Check if post is already indexed
		$is_indexed = get_post_meta($post->ID, '_pinecone_indexed', true);
		if ($is_indexed) {
			plugin_log("Post ID: " . $post->ID . " is already indexed. Skipping.");
			update_option('pinecone_last_processed_id', $post->ID);
			return;
		}

		// Generate embedding
		$embedding = getEmbedding($content);
		if (!$embedding) {
			plugin_log("Embedding failed for post ID: " . $post->ID);
			return;
		}

		// Log embedding dimensions
		$vector_length = is_array($embedding) ? count($embedding) : 0;
		plugin_log("Embedding generated successfully - dimensions: $vector_length");
		
		$nostr_event_id = get_post_meta($post->ID, 'nostr_event_id', true);
		
		$datetime_array = explode(" ", $post->post_date);
		
		$metadata = [];
		$metadata['post_id'] = $post->ID;
		
		if ($title) $metadata['title'] = $title;
		$metadata['content'] = sanitize_text_field(substr(str_replace("Powered by WPeMatico","",html_entity_decode(wp_strip_all_tags($post->post_content))), 0, 2500)) . '...';
		if ($categories_str) $metadata['categories'] = $categories_str;
		if ($date = $datetime_array[0]) $metadata['date'] = $datetime_array[0];
		if ($time = $datetime_array[1]) $metadata['time'] = $datetime_array[1];
		if ($timestamp = get_post_timestamp($post->ID)) $metadata['timestamp'] = (int)$timestamp;
		if ($source_url = esc_url(get_post_meta($post->ID, 'link', true))) $metadata['source_url'] = $source_url;
		if ($archive_url = esc_url(get_post_meta($post->ID, 'archive_link', true))) $metadata['archive_url'] = $archive_url;
		if ($author_name) $metadata['author'] = $author_name;
		if ($nostr_event_id) $metadata['nostr_event_id'] = $nostr_event_id;

		// Log detailed metadata
		plugin_log("Detailed metadata being sent to Pinecone: " . print_r($metadata, true));

		// Upsert to Pinecone
		$pinecone = new PineconeClient();
		$pinecone_data = [
			[
				'id' => 'post_' . $post->ID,
				'values' => $embedding,
				'metadata' => $metadata
			]
		];

		//plugin_log("Array of data sent to Pinecone: " . print_r($pinecone_data, true));

		if (!$pinecone->upsertVectors($pinecone_data)) {
			plugin_log("Pinecone upsert failed for post ID: " . $post->ID);
			return;
		}

		// Update tracking
		update_option('pinecone_last_processed_id', $post->ID);
		$update_response = update_post_meta($post->ID, '_pinecone_indexed', true);
		plugin_log("update_post_meta function to mark post as processed response: " . $update_response);
		plugin_log("Successfully processed post ID: " . $post->ID);
	}
	
	// Log memory usage and execution time
	$memory_usage = memory_get_usage();
	$peak_memory_usage = memory_get_peak_usage();
	$execution_time = timer_stop();

	plugin_log("Memory Usage: " . round($memory_usage / 1024 / 1024, 2) . " MB");
	plugin_log("Peak Memory Usage: " . round($peak_memory_usage / 1024 / 1024, 2) . " MB");
	plugin_log("Execution Time: " . $execution_time . " seconds");
	
});

// Function to check and log cron events
function check_cron_events() {
    $events = _get_cron_array();
    $cron_events = [];

    foreach ($events as $timestamp => $cron) {
        foreach ($cron as $hook => $args) {
            $cron_events[$hook][] = $timestamp;
        }
    }

    plugin_log("Scheduled Cron Events:");
    plugin_log(print_r($cron_events, true));
}

// Run cron event check on plugin activation
register_activation_hook(__FILE__, 'check_cron_events');

// Additional debugging: Check if cron job is being triggered
add_action('init', function() {
    if (isset($_GET['trigger_cron'])) {
        do_action('pinecone_index_cron');
        plugin_log("Manually triggered pinecone_index_cron via URL parameter.");
    }
});