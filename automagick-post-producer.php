<?php
/*
Plugin Name: AutoMagickal Post Producer
Plugin URI: https://lancesmith.cc
Description: Automatically generates posts using OpenAI GPT-4O-Mini for text and DALL-E 3 for images at scheduled times.
Version: 1.4
Author: Lance Smith
Author URI: https://lancesmith.cc
License: GPL2
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

if (!class_exists('AutoMagickal_Post_Producer')):

class AutoMagickal_Post_Producer {

    private $option_name = 'amp_producer_options';
    private $cron_hook = 'amp_producer_generate_post';
    private $error_log = array();

    public function __construct() {
        register_activation_hook(__FILE__, array($this, 'activation_hook'));
        register_deactivation_hook(__FILE__, array($this, 'deactivation_hook'));
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('init', array($this, 'schedule_event'));
        add_action($this->cron_hook, array($this, 'generate_post'));
        add_action('admin_post_amp_producer_test_generate', array($this, 'handle_test_generate'));
    }

    public function activation_hook() {
        $this->schedule_event();
    }

    public function deactivation_hook() {
        wp_clear_scheduled_hook($this->cron_hook);
    }

    public function schedule_event() {
        wp_clear_scheduled_hook($this->cron_hook);

        $options = get_option($this->option_name);
        if (!empty($options['frequency']) && !empty($options['time_of_day'])) {
            $frequency = $options['frequency'];
            $time_of_day = $options['time_of_day'];

            $hour = intval(substr($time_of_day, 0, 2));
            $minute = intval(substr($time_of_day, 3, 2));

            $timestamp = mktime($hour, $minute, 0);
            if ($timestamp <= time()) {
                switch ($frequency) {
                    case 'daily':
                        $timestamp = strtotime('tomorrow ' . $time_of_day);
                        break;
                    case 'twicedaily':
                        $timestamp = strtotime('+12 hours', $timestamp);
                        break;
                    case 'hourly':
                        $timestamp = strtotime('+1 hour', $timestamp);
                        break;
                    default:
                        $schedules = wp_get_schedules();
                        $interval = isset($schedules[$frequency]) ? $schedules[$frequency]['interval'] : 86400;
                        $timestamp = time() + $interval;
                }
            }

            wp_schedule_event($timestamp, $frequency, $this->cron_hook);
        }
    }

    public function add_settings_page() {
        add_options_page(
            'AutoMagickal Post Producer Settings',
            'AutoMagickal Post Producer',
            'manage_options',
            'amp_producer_settings',
            array($this, 'settings_page_html')
        );
    }

    public function register_settings() {
        register_setting('amp_producer_options_group', $this->option_name, array($this, 'sanitize_options'));

        add_settings_section('amp_producer_main_section', 'Settings', null, 'amp_producer_settings');

        $fields = array(
            'api_key' => 'OpenAI API Key',
            'topic_prompt' => 'Topic Prompt',
            'image_style_prompt' => 'Image Style Prompt',
            'frequency' => 'Post Frequency',
            'time_of_day' => 'Time of Day',
            'post_type' => 'Post Type'
        );

        foreach ($fields as $field => $label) {
            add_settings_field(
                $field,
                $label,
                array($this, $field . '_field'),
                'amp_producer_settings',
                'amp_producer_main_section'
            );
        }
    }

    public function sanitize_options($options) {
        if (isset($options['api_key'])) {
            $options['api_key'] = $this->encrypt_api_key(sanitize_text_field($options['api_key']));
        }
        if (isset($options['topic_prompt'])) {
            $options['topic_prompt'] = sanitize_textarea_field($options['topic_prompt']);
        }
        if (isset($options['image_style_prompt'])) {
            $options['image_style_prompt'] = sanitize_textarea_field($options['image_style_prompt']);
        }
        if (isset($options['frequency'])) {
            $options['frequency'] = sanitize_text_field($options['frequency']);
        }
        if (isset($options['time_of_day'])) {
            $options['time_of_day'] = sanitize_text_field($options['time_of_day']);
        }
        if (isset($options['post_type'])) {
            $options['post_type'] = sanitize_text_field($options['post_type']);
        }

        wp_clear_scheduled_hook($this->cron_hook);
        $this->schedule_event();

        return $options;
    }

    public function api_key_field() {
        $options = get_option($this->option_name);
        $api_key_encrypted = isset($options['api_key']) ? $options['api_key'] : '';
        $api_key = $this->decrypt_api_key($api_key_encrypted);

        echo '<input type="password" name="' . $this->option_name . '[api_key]" value="' . esc_attr($api_key) . '" />';

        if (!empty($api_key)) {
            $test_result = $this->test_api_key($api_key);
            echo $test_result ? ' <span style="font-size: 20px;">✅</span>' : ' <span style="font-size: 20px;">❌</span>';
        }
    }

    public function topic_prompt_field() {
        $options = get_option($this->option_name);
        $topic_prompt = isset($options['topic_prompt']) ? $options['topic_prompt'] : '';
        echo '<textarea name="' . $this->option_name . '[topic_prompt]" rows="5" cols="50">' . esc_textarea($topic_prompt) . '</textarea>';
    }

    public function image_style_prompt_field() {
        $options = get_option($this->option_name);
        $image_style_prompt = isset($options['image_style_prompt']) ? $options['image_style_prompt'] : '';
        echo '<textarea name="' . $this->option_name . '[image_style_prompt]" rows="5" cols="50">' . esc_textarea($image_style_prompt) . '</textarea>';
    }

    public function frequency_field() {
        $options = get_option($this->option_name);
        $frequency = isset($options['frequency']) ? $options['frequency'] : 'daily';
        $schedules = wp_get_schedules();

        echo '<select name="' . $this->option_name . '[frequency]">';
        foreach ($schedules as $key => $schedule) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($frequency, $key, false) . '>' . esc_html($schedule['display']) . '</option>';
        }
        echo '</select>';
    }

    public function time_of_day_field() {
        $options = get_option($this->option_name);
        $time_of_day = isset($options['time_of_day']) ? $options['time_of_day'] : '00:00';
        echo '<input type="time" name="' . $this->option_name . '[time_of_day]" value="' . esc_attr($time_of_day) . '" />';
    }

    public function post_type_field() {
        $options = get_option($this->option_name);
        $post_type = isset($options['post_type']) ? $options['post_type'] : 'post';
        $post_types = get_post_types(array('public' => true), 'objects');

        echo '<select name="' . $this->option_name . '[post_type]">';
        foreach ($post_types as $key => $pt) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($post_type, $key, false) . '>' . esc_html($pt->labels->singular_name) . '</option>';
        }
        echo '</select>';
    }

    public function settings_page_html() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $error_message = isset($_GET['amp_producer_error']) ? sanitize_text_field($_GET['amp_producer_error']) : '';
        $success_message = isset($_GET['amp_producer_success']) ? sanitize_text_field($_GET['amp_producer_success']) : '';

        ?>
        <div class="wrap">
            <h1>AutoMagickal Post Producer Settings</h1>
            <?php if (!empty($success_message)): ?>
                <div id="message" class="updated notice is-dismissible">
                    <p><?php echo nl2br(esc_html($success_message)); ?></p>
                </div>
            <?php elseif (!empty($error_message)): ?>
                <div id="message" class="error notice is-dismissible">
                    <p>Error: <?php echo nl2br(esc_html($error_message)); ?></p>
                </div>
            <?php endif; ?>
            <form action="options.php" method="post">
                <?php
                settings_fields('amp_producer_options_group');
                do_settings_sections('amp_producer_settings');
                submit_button();
                ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('amp_producer_test_generate', 'amp_producer_nonce'); ?>
                <input type="hidden" name="action" value="amp_producer_test_generate">
                <?php submit_button('Test Generation', 'secondary'); ?>
            </form>
        </div>
        <?php
    }

    private function encrypt_api_key($api_key) {
        $encryption_key = wp_salt('auth');
        $iv = substr(hash('sha256', $encryption_key), 0, 16);
        return openssl_encrypt($api_key, 'AES-256-CBC', $encryption_key, 0, $iv);
    }

    private function decrypt_api_key($encrypted_api_key) {
        if (empty($encrypted_api_key)) {
            return '';
        }
        $encryption_key = wp_salt('auth');
        $iv = substr(hash('sha256', $encryption_key), 0, 16);
        return openssl_decrypt($encrypted_api_key, 'AES-256-CBC', $encryption_key, 0, $iv);
    }

    private function test_api_key($api_key) {
        $response = wp_remote_get('https://api.openai.com/v1/models', array(
            'headers' => array('Authorization' => 'Bearer ' . $api_key),
            'timeout' => 10,
        ));

        return !is_wp_error($response) && wp_remote_retrieve_response_code($response) == 200;
    }

    public function generate_post($is_test = false) {
        $this->error_log = array();
        $options = get_option($this->option_name);
        $api_key = $this->decrypt_api_key(isset($options['api_key']) ? $options['api_key'] : '');

        if (empty($api_key)) {
            $this->log_error('OpenAI API key is not set.');
            return $this->get_error_log();
        }

        $topic_prompt = isset($options['topic_prompt']) ? $options['topic_prompt'] : '';
        $image_style_prompt = isset($options['image_style_prompt']) ? $options['image_style_prompt'] : '';
        $post_type = isset($options['post_type']) ? $options['post_type'] : 'post';

        $topic = $this->generate_text($api_key, $topic_prompt);
        if (!$topic) {
            $this->log_error('Failed to generate topic.');
            return $this->get_error_log();
        }

        $post_content_prompt = 'Write a detailed and engaging article about "' . $topic . '". The response should be pure HTML content suitable for the Gutenberg editor, without any preamble or code blocks.';
        $post_content = $this->generate_text($api_key, $post_content_prompt);
        if (!$post_content) {
            $this->log_error('Failed to generate post content.');
            return $this->get_error_log();
        }

        $post_content = $this->clean_content($post_content);

        $title_prompt = 'Based on the following content, create an engaging and descriptive title without quotes or special characters: "' . strip_tags($post_content) . '"';
        $title = $this->generate_text($api_key, $title_prompt);
        if (!$title) {
            $this->log_error('Failed to generate title.');
            return $this->get_error_log();
        }

        $title = $this->clean_title($title);

        $image_prompt = $this->generate_image_prompt($title, $post_content, $image_style_prompt);
        $image_url = $this->generate_image($api_key, $image_prompt);

        $post_data = array(
            'post_title'   => $title,
            'post_content' => $post_content,
            'post_status'  => 'publish',
            'post_type'    => $post_type,
        );

        $post_id = wp_insert_post($post_data);

        if (is_wp_error($post_id)) {
            $this->log_error('Failed to create post: ' . $post_id->get_error_message());
            return $this->get_error_log();
        }

        $image_result = false;
        if ($image_url) {
            $image_result = $this->set_featured_image($post_id, $image_url);
        } else {
            $this->log_error('Failed to generate image.');
        }

        if ($is_test) {
            return array(
                'text_generated' => 1,
                'image_generated' => $image_url ? 2 : 0,
                'image_url' => $image_url,
                'image_posted' => $image_result === true ? 1 : 0,
                'post_created' => 1,
                'post_id' => $post_id,
                'error_log' => $this->get_error_log(),
                'image_prompt' => $image_prompt
            );
        }

        return true;
    }

    private function generate_text($api_key, $prompt) {
        $args = array(
            'model' => 'gpt-4o-mini',
            'messages' => array(
                array('role' => 'user', 'content' => $prompt),
            ),
            'max_tokens' => 1500,
        );

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ),
            'body' => json_encode($args),
            'timeout' => 60,
        ));

        if (is_wp_error($response)) {
            $this->log_error('Text generation error: ' . $response->get_error_message());
            return false;
        }

        $body = json_decode($response['body'], true);
        if (isset($body['choices'][0]['message']['content'])) {
            return trim($body['choices'][0]['message']['content']);
        } else {
            $this->log_error('Invalid response from OpenAI for text generation.');
            return false;
        }
    }

    private function generate_image_prompt($title, $content, $style_prompt) {
        $content_summary = wp_trim_words(strip_tags($content), 50, '...');
        $prompt = "Create an image prompt based on this title and content summary:\n\n";
        $prompt .= "Title: $title\n\n";
        $prompt .= "Content: $content_summary\n\n";
        $prompt .= "The image prompt should be concise (max 100 characters) and capture the essence of the article. Include key visual elements and atmosphere.";

        $image_prompt = $this->generate_text($this->decrypt_api_key(get_option($this->option_name)['api_key']), $prompt);
        
        // Ensure the prompt is not too long
        $image_prompt = wp_trim_words($image_prompt, 15, '');
        
        // Append style prompt
        $image_prompt .= ', ' . $style_prompt;

        return $image_prompt;
    }

    private function generate_image($api_key, $prompt) {
        $args = array(
            'model' => 'dall-e-3',
            'prompt' => $prompt,
            'n' => 1,
            'size' => '1024x1024',
            'quality' => 'standard',
            'response_format' => 'url',
        );

        $response = wp_remote_post('https://api.openai.com/v1/images/generations', array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ),
            'body' => json_encode($args),
            'timeout' => 60,
        ));

        if (is_wp_error($response)) {
            $this->log_error('Image generation error: ' . $response->get_error_message());
            return false;
        }

        $body = json_decode($response['body'], true);

        if (isset($body['data'][0]['url'])) {
            return $body['data'][0]['url'];
        } else {
            $this->log_error('Invalid response from DALL-E 3 API: ' . print_r($body, true));
            return false;
        }
    }

    private function set_featured_image($post_id, $image_url) {
        if (empty($image_url) || !filter_var($image_url, FILTER_VALIDATE_URL)) {
            $this->log_error('Invalid image URL: ' . $image_url);
            return false;
        }

        $tmp = download_url($image_url);

        if (is_wp_error($tmp)) {
            $this->log_error('Error downloading image: ' . $tmp->get_error_message());
            return false;
        }

        $file_array = array();
        $image_name = basename(parse_url($image_url, PHP_URL_PATH));
        if (!preg_match('/\.(jpg|jpeg|png|gif)$/i', $image_name)) {
            $image_name = 'image_' . time() . '.png';
        }

        $file_array['name'] = $image_name;
        $file_array['tmp_name'] = $tmp;

        if (!function_exists('media_handle_sideload')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/media.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');
        }

        $id = media_handle_sideload($file_array, $post_id);

        if (is_wp_error($id)) {
            @unlink($tmp);
            $this->log_error('Error uploading image: ' . $id->get_error_message());
            return false;
        }

        if (!set_post_thumbnail($post_id, $id)) {
            $this->log_error('Error setting featured image for post ID: ' . $post_id);
            return false;
        }

        return true;
    }

    private function clean_content($content) {
        $content = preg_replace('/^```html\s*/', '', $content);
        $content = preg_replace('/^```\s*/', '', $content);
        $content = preg_replace('/\s*```$/', '', $content);
        $content = preg_replace('/^(.*?)<\s*[^>]+>/s', '', $content);
        return trim($content);
    }

    private function clean_title($title) {
        return trim(str_replace(array('"', "'", '**', '##', '#'), '', $title));
    }

    private function log_error($message) {
        $this->error_log[] = $message;
    }

    private function get_error_log() {
        return implode("\n", $this->error_log);
    }

    public function handle_test_generate() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized user');
        }

        check_admin_referer('amp_producer_test_generate', 'amp_producer_nonce');

        $result = $this->generate_post(true);

        if (is_array($result)) {
            $message = "Test generation results:\n";
            $message .= "Text generated: " . ($result['text_generated'] ? 'Yes' : 'No') . "\n";
            $message .= "Image generated: " . ($result['image_generated'] ? 'Yes' : 'No') . "\n";
            if ($result['image_generated']) {
                $message .= "Image URL: " . $result['image_url'] . "\n";
            }
            $message .= "Image posted as featured image: " . ($result['image_posted'] ? 'Yes' : 'No') . "\n";
            $message .= "Post created: " . ($result['post_created'] ? 'Yes' : 'No') . "\n";
            if ($result['post_created']) {
                $message .= "Post ID: " . $result['post_id'] . "\n";
            }
            $message .= "Image Prompt: " . $result['image_prompt'] . "\n";
            if (!empty($result['error_log'])) {
                $message .= "\nError Log:\n" . $result['error_log'];
            }
            wp_redirect(add_query_arg('amp_producer_success', urlencode($message), wp_get_referer()));
        } else {
            wp_redirect(add_query_arg('amp_producer_error', urlencode($result), wp_get_referer()));
        }
        exit;
    }
}

new AutoMagickal_Post_Producer();

endif; // End if class_exists
