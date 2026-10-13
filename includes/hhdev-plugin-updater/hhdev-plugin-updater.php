<?php

/**
 * Class hhdevPluginUpdater
 *
 * Provides automatic update functionality for WordPress plugins
 * hosted on Codeberg/Forgejo.
 *
 * Version: 1.2.1
 *
 * The class:
 * - Checks the Codeberg Releases API for new plugin versions.
 * - Compares the latest release version with the installed version.
 * - Integrates with the WordPress plugin update system.
 * - Provides plugin information and release notes in WordPress.
 * - Downloads the plugin ZIP directly from the Codeberg release.
 *
 * Codeberg repository:
 * https://codeberg.org/hhdev/hahadev-remote-blacklist-update
 *
 * The repository API URL should point to:
 * https://codeberg.org/api/v1/repos/{owner}/{repository}/releases
 *
 *
 * @package herbert hoekstra - haha.nl
 * @author  herbert hoekstra - haha.nl
 * @since   1.0.0
 */

namespace hhdev\CodebergPluginUpdater\v121;

if (!class_exists('hhdev\CodebergPluginUpdater\v121\PluginUpdater')) {

  class PluginUpdater
  {
      private $plugin_file;
      private $plugin_slug;
      private $repo_api_url;
      private $token;
      private $repo_owner;
      private $repo_name;
      private $repo_base_url;
      private $repo_url;
      private $plugin_name;

      public function __construct(
          $plugin_file,
          $plugin_slug,
          $repo_api_url,
          $token = '',
          $plugin_name = ''
      ) {
          $this->plugin_file  = $plugin_file;
          $this->plugin_slug  = $plugin_slug;
          $this->repo_api_url = $repo_api_url;
          $this->token        = $token;
          $this->plugin_name  = $plugin_name ?: 'Plugin';

          /*
           * API URL:
           * https://codeberg.org/api/v1/repos/{owner}/{repo}/releases
           */
          if (
              !preg_match(
                  '#^https://codeberg\.org/api/v1/repos/([^/]+)/([^/]+)/releases/?$#',
                  $repo_api_url,
                  $matches
              )
          ) {
              return;
          }

          $this->repo_owner = $matches[1];
          $this->repo_name  = $matches[2];

          // Correct Codeberg repository URLs.
          $this->repo_base_url = 'https://codeberg.org';
          $this->repo_url      = $this->repo_base_url . '/' .
                                 $this->repo_owner . '/' .
                                 $this->repo_name;

          add_filter(
              'pre_set_site_transient_update_plugins',
              [$this, 'check_for_update']
          );

          add_filter(
              'plugins_api',
              [$this, 'plugin_info'],
              10,
              3
          );

          add_filter(
              'http_request_args',
              [$this, 'package_download_auth'],
              10,
              2
          );

          add_filter(
              'upgrader_source_selection',
              [$this, 'source_selection'],
              10,
              4
          );
      }


      /**
       * Check Codeberg for a newer release.
       */
       public function check_for_update($transient)
       {
           if (empty($transient->checked)) {
               return $transient;
           }

           $repo_data = $this->fetch_repo_data();

           if (empty($repo_data) || !is_array($repo_data)) {
              // error_log('HHDEV updater: No release data received.');
               return $transient;
           }

           $latest = $this->get_latest_release($repo_data);

           if (!$latest || empty($latest->tag_name)) {
              // error_log('HHDEV updater: No valid release found.');
               return $transient;
           }

           $latest_version = ltrim($latest->tag_name, 'vV');

           $plugin_data = get_plugin_data(
               $this->plugin_file,
               false,
               false
           );

           $current_version = $plugin_data['Version'];

           /* error_log(
               'HHDEV updater: Current=' . $current_version .
               ' Latest=' . $latest_version .
               ' Plugin=' . plugin_basename($this->plugin_file)
           ); */

           if (version_compare($current_version, $latest_version, '<')) {

               $download_url = $this->get_release_download_url($latest);

              /* error_log(
                   'HHDEV updater: UPDATE AVAILABLE. Package=' .
                   $download_url
               );*/

               $plugin_basename = plugin_basename($this->plugin_file);

               $transient->response[$plugin_basename] = (object) [
                   'slug'        => $this->plugin_slug,
                   'plugin'      => $plugin_basename,
                   'new_version' => $latest_version,
                   'url'         => $this->repo_url,
                   'package'     => $download_url,
               ];
           }

           return $transient;
       }

       /*
       makes the wp core updater also handle the zip corectly
       */
       public function source_selection($source, $remote_source, $upgrader, $hook_extra)
       {
           if (is_wp_error($source)) {
               return $source;
           }

           if (
               empty($hook_extra['plugin']) ||
               $hook_extra['plugin'] !== plugin_basename($this->plugin_file)
           ) {
               return $source;
           }

           $plugin_filename = basename($this->plugin_file);

           /*
            * First check $source itself.
            */
           if (
               file_exists(
                   trailingslashit($source) . $plugin_filename
               )
           ) {
               return trailingslashit($source);
           }

           /*
            * Search one directory level down.
            */
           $subdirs = glob(
               trailingslashit($source) . '*',
               GLOB_ONLYDIR
           );

           if (!empty($subdirs)) {

               foreach ($subdirs as $subdir) {

                   if (
                       file_exists(
                           trailingslashit($subdir) . $plugin_filename
                       )
                   ) {
                       return trailingslashit($subdir);
                   }
               }
           }

           return new WP_Error(
               'invalid_plugin_package',
               'Plugin main file "' . $plugin_filename .
               '" was not found in the downloaded package.'
           );
       }

      /**
       * Provide information shown in the WordPress plugin update dialog.
       */
      public function plugin_info($false, $action, $args)
      {
          if (
              $action !== 'plugin_information' ||
              empty($args->slug) ||
              $args->slug !== $this->plugin_slug
          ) {
              return $false;
          }

          $repo_data = $this->fetch_repo_data();

          if (empty($repo_data) || !is_array($repo_data)) {
              return $false;
          }

          $latest = $this->get_latest_release($repo_data);

          if (!$latest || empty($latest->tag_name)) {
              return $false;
          }

          $download_url = $this->get_release_download_url($latest);

          if (!$download_url) {
              return $false;
          }

          $latest_version = ltrim($latest->tag_name, 'vV');

          /*
           * Changelog.
           */
          $changelog_content = '';

          if (!empty($latest->body)) {
              $changelog_content = nl2br(
                  esc_html($latest->body)
              );
          } else {
              $changelog_content = 'No changelog available for this release.';
          }

          $release_url = $this->repo_url .
                         '/releases/tag/' .
                         rawurlencode($latest->tag_name);

          $changelog_content .= sprintf(
              '<p><a href="%s" target="_blank" rel="noopener noreferrer">' .
              '📝 View full changelog on Codeberg' .
              '</a></p>',
              esc_url($release_url)
          );

          return (object) [
              'name'         => $this->plugin_name,
              'slug'         => $this->plugin_slug,
              'version'      => $latest_version,
              'author'       => sprintf(
                  '<a href="%s">%s</a>',
                  esc_url(
                      $this->repo_base_url . '/' . $this->repo_owner
                  ),
                  esc_html($this->repo_owner)
              ),
              'homepage'     => $this->repo_url,
              'download_link'=> $download_url,

              'sections' => [
                  'description' =>
                      'Plugin with updates from a Codeberg/Forgejo repository.',

                  'changelog' =>
                      $changelog_content,
              ],
          ];
      }

      /**
       * Add authentication to Codeberg download requests.
       */
      public function package_download_auth($args, $url)
      {
          /*
           * Only modify requests belonging to this repository.
           */
          if (
              strpos($url, $this->repo_url) === 0 ||
              strpos($url, 'https://codeberg.org/' .
                           $this->repo_owner . '/') === 0
          ) {

              if (!empty($this->token)) {
                  $args['headers']['Authorization'] =
                      'token ' . $this->token;
              }

              $args['headers']['User-Agent'] =
                  'WordPress-Updater';
          }

          return $args;
      }


      /**
       * Get releases from Codeberg.
       */
      private function fetch_repo_data()
      {
          $headers = [
              'User-Agent' => 'WordPress-Updater',
              'Accept'     => 'application/json',
          ];

          if (!empty($this->token)) {
              $headers['Authorization'] =
                  'token ' . $this->token;
          }

          $response = wp_remote_get(
              $this->repo_api_url,
              [
                  'headers' => $headers,
                  'timeout' => 15,
              ]
          );

          if (is_wp_error($response)) {
              return null;
          }

          $status_code = wp_remote_retrieve_response_code($response);

          if ($status_code < 200 || $status_code >= 300) {
              return null;
          }

          $body = wp_remote_retrieve_body($response);

          if (empty($body)) {
              return null;
          }

          $data = json_decode($body);

          if (
              json_last_error() !== JSON_ERROR_NONE ||
              !is_array($data)
          ) {
              return null;
          }

          return $data;
      }


      /**
       * Get the newest release.
       *
       * Sort by created_at instead of assuming the API response
       * is always in the desired order.
       */
      private function get_latest_release($releases)
      {
          $releases = array_filter(
              $releases,
              function ($release) {
                  return !empty($release->tag_name);
              }
          );

          if (empty($releases)) {
              return null;
          }

          usort(
              $releases,
              function ($a, $b) {

                  $date_a = !empty($a->created_at)
                      ? strtotime($a->created_at)
                      : 0;

                  $date_b = !empty($b->created_at)
                      ? strtotime($b->created_at)
                      : 0;

                  return $date_b <=> $date_a;
              }
          );

          return reset($releases);
      }


      /**
       * Get the ZIP download URL.
       *
       * Prefer an uploaded release ZIP.
       * Otherwise use the Codeberg source archive.
       */
      private function get_release_download_url($release)
      {
          /*
           * First look for an uploaded ZIP asset.
           */
          if (
              !empty($release->assets) &&
              is_array($release->assets)
          ) {

              foreach ($release->assets as $asset) {

                  if (
                      !empty($asset->name) &&
                      preg_match('/\.zip$/i', $asset->name)
                  ) {

                      if (!empty($asset->browser_download_url)) {
                          return $asset->browser_download_url;
                      }

                      if (!empty($asset->url)) {
                          return $asset->url;
                      }
                  }
              }
          }

          /*
           * No uploaded ZIP:
           *
           * Use Codeberg's source archive.
           */
          return $this->repo_url .
                 '/archive/' .
                 rawurlencode($release->tag_name) .
                 '.zip';
      }
  }
}
