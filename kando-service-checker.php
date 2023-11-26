<?php

/**
 * Plugin Name: Kando service checker
 * Author: Mohammad Zarifiyan
 * Description: Checks existence of kando API services and notify administrator about them.
 * Version: 1.0.0
 */

add_filter('cron_schedules', function($schedules) {
    $schedules['10_minutes'] = array(
        'interval' => 600,
        'display' => __('Every 10 Minutes'),
    );
    return $schedules;
});

add_action('init', 'schedule_kando_service_checker');
add_action('check_services', 'check_services');

function schedule_kando_service_checker() {
    if (!wp_next_scheduled('check_services')) {
        wp_schedule_event(time(), '10_minutes', 'check_services');
    }
}

function check_services() {
    global $wpdb;
    $missing_services = [];
    $failed_providers = [];
    $providers = $wpdb->get_results('SELECT * FROM wp_samyar_api_provider where status=1');

    foreach ($providers as $provider) {
        $ch = curl_init($provider->url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HEADER => false,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_USERAGENT => 'Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'key' => $provider->api_key,
                'token' => $provider->api_key,
                'action' => 'services',
            ])
        ]);
        $result = curl_exec($ch);

        if (curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200) {
            $failed_providers[] = $provider->name;
            curl_close($ch);
            continue;
        }

        curl_close($ch);

        $decoded_result = json_decode($result);
        $api_services_id_list = [];

        // Update min/max columns
        foreach ($decoded_result as $apiService) {
            $id = strval($apiService->service ?? $apiService->ID ?? $apiService->id ?? $apiService->service_id);
            $api_services_id_list[] = $id;
            $query = $wpdb->prepare(
                'UPDATE wp_samyar_services SET min=%d,max=%d WHERE api_provider_id=%s AND api_service_id=%s',
                (int) $apiService->min,
                (int) $apiService->max,
                (string) $provider->id,
                $id
            );
            $wpdb->query($query);
        }

        // Merge provider disabled services to $missing_services
        $placeHolders = implode(', ', array_fill(0, count($api_services_id_list), '%s'));
        $query = $wpdb->prepare(
            "SELECT id, name FROM wp_samyar_services WHERE api_provider_id=%s AND api_service_id NOT IN ($placeHolders)",
            (string) $provider->id,
            ...$api_services_id_list
        );
        $missing_services = array_merge($missing_services, $wpdb->get_results($query));
    }

    // Disable missing services
    $missing_services_id_list = array_map(
        function ($service) {
            return $service->id;
        },
        $missing_services
    );
    $placeHolders = implode(', ', array_fill(0, count($missing_services_id_list), '%s'));
    $wpdb->query(
        $wpdb->prepare("UPDATE wp_samyar_services SET api_provider_id=0, api_service_id='',add_type='manual' WHERE id IN ($placeHolders)", ...$missing_services_id_list)
    );

    // Send report
    if (count($missing_services) > 0) {
        send_service_disablement_report($missing_services);
    }
    if (count($failed_providers) > 0) {
        send_provider_failure_report($failed_providers);
    }
}

function send_service_disablement_report(array $disabled_services)
{
    $admin_email = get_option('admin_email');

    $disabled_services = array_map(
        function ($service) {
            return 'Service ID: ' . $service->id . PHP_EOL . 'Service name: ' . $service->name;
        },
        $disabled_services
    );
    $disabled_services = implode(PHP_EOL.PHP_EOL, $disabled_services);

    $message = sprintf(<<<TEXT
Hello
These services were changed to manual mode due to deletion or disablement in the provider's API on your website.

%s
TEXT, $disabled_services);

    wp_mail($admin_email, 'Service disabled!', $message);
}

function send_provider_failure_report(array $providers)
{
    $admin_email = get_option('admin_email');

    $message = sprintf(<<<TEXT
Hello
The API of these providers returned an incorrect response. Please check them out.

%s
TEXT, implode(PHP_EOL, $providers));

    wp_mail($admin_email, 'API Provider returned invalid response', $message);
}
