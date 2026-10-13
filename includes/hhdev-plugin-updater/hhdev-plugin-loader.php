<?php

// hhdev-plugin-updater-loader
// ------------

namespace hhdev\CodebergPluginUpdater;

class Loader {
    private static $loaded = false;
    private static $instance = null;

    public static function load($version = 'v120') {
        if (self::$loaded) {
            return; // Already loaded
        }

        // Determine which file to load based on version
        $file_path = dirname(__FILE__) . '/' . $version . '/-plugin-updater.php';

        if (file_exists($file_path)) {
            require_once $file_path;
            self::$loaded = true;
        }
    }
}
