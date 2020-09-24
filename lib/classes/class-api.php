<?php

/**
 * API Handler
 *
 *
 *
 * @since 1.0.0
 */

namespace wpCloud\StatelessMedia {

  use wpCloud\StatelessMedia\Sync\ImageSync;

  if (!class_exists('wpCloud\StatelessMedia\API')) {

    /**
     * Class API
     *
     * @package wpCloud\StatelessMedia
     */
    final class API {

      /**
       * Decoded auth_token data
       *
       * @var null|\stdClass
       */
      static private $tokenData = null;

      /**
       * Validate auth token and save data for further processing
       *
       * @param \WP_REST_Request $request
       * @return bool|\WP_Error
       */
      static public function authCheck(\WP_REST_Request $request) {
        $auth_token = $request->get_header('authorization');
        if (!$auth_token) return false;
        try {
          self::$tokenData = Utility::verify_jwt_token($auth_token);
        } catch (\Exception $e) {
          self::$tokenData = null;
          return new \WP_Error('auth_failed', $e->getMessage(), ['status' => 401]);
        }
        return true;
      }

      /**
       * API Status Endpoint.
       *
       * @return \WP_REST_Response
       */
      static public function status() {
        return new \WP_REST_Response(array(
          "ok" => true,
          "message" => "API up."
        ), 200);
      }

      /**
       * Get settings
       *
       * @todo Implement this if needed
       * @param \WP_REST_Request $request
       * @return \WP_REST_Response|\WP_Error
       */
      static public function getSettings(\WP_REST_Request $request) {
        return new \WP_Error('not_implemented', 'Method not implemented', ['status' => 501]);
      }

      /**
       * Update settings
       *
       * @param \WP_REST_Request $request
       * @return \WP_REST_Response|\WP_Error
       */
      static public function updateSettings(\WP_REST_Request $request) {
        if (self::$tokenData === null || empty(self::$tokenData->user_id)) {
          return new \WP_Error('unauthorized', 'Auth token looks incorrect', ['status' => 401]);
        }
        $is_gae                 = isset($_SERVER["GAE_VERSION"]) ? true : false;
        $upload_dir             = wp_upload_dir();
        $is_upload_dir_writable = is_writable($upload_dir['basedir']);

        try {
          $queryParams = $request->get_json_params();
          if (empty($queryParams)) throw new \Exception('Query is empty');

          $bucketName = isset($queryParams['bucket_name']) ? $queryParams['bucket_name'] : null;
          $privateKeyData = isset($queryParams['private_key_data']) ? $queryParams['private_key_data'] : null;

          if (!$bucketName || !$privateKeyData) {
            throw new \Exception('bucket_name and private_key_data are required');
          }

          if ($privateKeyData) {
            $privateKeyData = base64_decode($privateKeyData);
          }

          switch (self::$tokenData->is_network) {
            case true:
              if (!user_can(self::$tokenData->user_id, 'manage_network_options')) {
                return new \WP_Error('not_allowed', 'Sorry, you are not allowed to perform this action', ['status' => 403]);
              }
              /**
               * If Google App Engine detected - set Stateless mode
               * and Google App Engine compatibility by default
               */
              if ($is_gae || !$is_upload_dir_writable) {
                update_site_option('sm_mode', 'stateless');

                $modules = get_site_option('stateless-modules', array());
                if ($is_gae && empty($modules['google-app-engine']) || $modules['google-app-engine'] != 'true') {
                  $modules['google-app-engine'] = 'true';
                  update_site_option('stateless-modules', $modules);
                }
              } elseif (get_site_option('sm_mode', 'disabled') == 'disabled') {
                update_site_option('sm_mode', 'cdn');
              }
              update_site_option('sm_bucket', $bucketName);
              update_site_option('sm_key_json', $privateKeyData);
              break;

            case false:
              if (!user_can(self::$tokenData->user_id, 'manage_options')) {
                return new \WP_Error('not_allowed', 'Sorry, you are not allowed to perform this action', ['status' => 403]);
              }
              /**
               * If Google App Engine detected - set Stateless mode
               * and Google App Engine compatibility by default
               */
              if ($is_gae || !$is_upload_dir_writable) {
                update_option('sm_mode', 'stateless');

                $modules = get_option('stateless-modules', array());
                if ($is_gae && empty($modules['google-app-engine']) || $modules['google-app-engine'] != 'true') {
                  $modules['google-app-engine'] = 'true';
                  update_option('stateless-modules', $modules);
                }
              } elseif (get_option('sm_mode', 'disabled') == 'disabled') {
                update_option('sm_mode', 'cdn');
              }
              update_option('sm_bucket', $bucketName);
              update_option('sm_key_json', $privateKeyData);
              break;
          }

          return new \WP_REST_Response(array(
            'ok' => true,
            'message' => 'Settings updated successfully'
          ));
        } catch (\Throwable $e) {
          return new \WP_Error('internal_server_error', $e->getMessage(), ['status' => 500]);
        }
      }

      /**
       * Get media count statistics
       * 
       * @return \WP_REST_Response|\WP_Error
       */
      static public function syncGetProcesses() {
        try {
          $syncClasses = Utility::get_available_sync_classes();

          return new \WP_REST_Response(array(
            'ok' => true,
            'data' => $syncClasses
          ));

          // remove this once ported
          // $statsSql = [
          //   'other' => "SELECT count(*) FROM $wpdb->posts WHERE post_type = 'attachment' AND post_mime_type NOT LIKE 'image/%' ORDER BY ID DESC"
          // ];

          // $stats['custom'] = count(array_unique(apply_filters('sm:sync::nonMediaFiles', [])));
        } catch (\Throwable $e) {
          return new \WP_Error('internal_server_error', $e->getMessage(), ['status' => 500]);
        }
      }

      /**
       * Run sync by processing class id
       */
      static public function syncRun(\WP_REST_Request $request) {
        try {
          $params = wp_parse_args($request->get_params(), [
            'id' => null,
            'limit' => null
          ]);

          if (empty($params['id']) || !class_exists($params['id'])) {
            throw new \Exception(sprintf('Processing class not found: %s', $params['id']));
          }

          $processingClass = $params['id'];

          return new \WP_REST_Response(array(
            'ok' => $processingClass::instance()->start($params)
          ));
        } catch (\Throwable $e) {
          return new \WP_Error('internal_server_error', $e->getMessage(), ['status' => 500]);
        }
      }
    }
  }
}
