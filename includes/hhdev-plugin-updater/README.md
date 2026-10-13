# Codeberg Plugin Updater

A WordPress plugin updater class that enables automatic updates from private Codeberg (Forgejo) repositories.

## Description

This PHP class provides a seamless way to enable automatic updates for your WordPress plugins hosted on Codeberg (or any Forgejo-compatible platform). It integrates with WordPress's native update system, allowing your plugin to receive updates directly from your Codeberg repository.

### Key Features

- **Automatic Updates**: Support for automatic updates of the plugin when new releases are published
- **Private Repository Support**: Works with both public and private Codeberg repositories
- **Version Management**: Uses Git tags for version control
- **Release Assets**: Supports both release assets and source code archives
- **Secure Authentication**: Supports token-based authentication for private repositories
- **WordPress Native Integration**: Uses WordPress's built-in update system
- **Changelog Display**: Shows release notes in the WordPress plugin details modal
- **Auto-activation**: Automatically reactivates the plugin after update

## Installation

### 1. Add the Class to Your Plugin

Add the `hhdev-plugin-updater.php` file in your plugin project:

### 2. Initialize the Updater

In your main plugin file, initialize the updater with your Codeberg repository details:

```php
// change path to match updater file location!
require_once plugin_dir_path(__FILE__) . 'hhdev-plugin-updater.php';
// change version number to the used updater class version!
use hhdev\CodebergPluginUpdater\v120\PluginUpdater;

$updater = new PluginUpdater(
    __FILE__,
    'your-plugin-slug',
    'https://codeberg.org/api/v1/repos/username/plugin/releases',
    'your_token',
    'Your Plugin Name'
);

// Optional: Enable automatic updates functionality
add_filter('auto_update_plugin', function($update, $item) {
    if (isset($item->slug) && $item->slug === 'your-plugin-slug') {
        return true;
    }
    return $update;
}, 10, 2);
```
**Notice:** Always use the correct version number in the 'use' declaration!

### 3. Create a Release on Codeberg

For the updater to work, you need to:

1. Create a **release** on Codeberg
2. Tag it with a version number (e.g., `v1.0.0`, `v1.2.3`)
3. Either:
   - Attach a ZIP file of your plugin as a release asset, OR
   - Let the updater use the source code archive

## Usage

### Basic Usage with Public Repository

```php
$updater = new PluginUpdater(
    __FILE__,
    'your-plugin-slug/your-plugin-slug.php',
    'https://codeberg.org/api/v1/repos/my-username/my-awesome-plugin/releases'
);
```

### Usage with Private Repository

```php
$updater = new PluginUpdater(
    __FILE__,
    'your-plugin-slug/your-plugin-slug.php',
    'https://codeberg.org/api/v1/repos/my-username/my-awesome-plugin/releases',
    'your_codeberg_personal_access_token'
);
```

### Using Plugin Header Name

```php
$plugin_data = get_plugin_data(__FILE__);
$updater = new PluginUpdater(
    __FILE__,
    'your-plugin-slug/your-plugin-slug.php',
    'https://codeberg.org/api/v1/repos/my-username/my-awesome-plugin/releases',
    'your_codeberg_token',
    $plugin_data['Name'] // Auto-detects plugin name from header
);
```

## Configuration

### Constructor Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `$plugin_file` | string | Yes | Path to the main plugin file (use `__FILE__`) |
| `$plugin_slug` | string | Yes | Plugin slug (must match the folder name) |
| `$repo_api_url` | string | Yes | Codeberg API URL for releases |
| `$token` | string | No | Codeberg personal access token (required for private repos) |
| `$plugin_name` | string | No | Display name for the plugin (defaults to 'Plugin') |

### Getting a Codeberg Token

For private repositories, you need a personal access token:

1. Go to Codeberg → Settings → Applications
2. Generate a new token with `repo` scope
3. Add the token to your plugin configuration

## Release Management

### Creating a Release

1. Push your changes to Codeberg
2. Go to your repository → Releases → Create Release
3. Choose a tag name (e.g., `v1.0.0`)
4. Add a title and description (this becomes the changelog)
5. **Optionally** attach a ZIP file containing your plugin
6. Publish the release

### Version Format

The class expects versions in the format:
- `v1.0.0` ✓
- `v1.2.3` ✓
- `v2.0.0-beta` ✓
- `1.0.0` (works but without 'v' prefix)

### Changelog Display

The release description (body) is automatically displayed in:
- The WordPress plugin details modal
- The updates section

You can format the release description using markdown for better readability.

## How It Works

1. **Version Check**: WordPress checks for updates using the `check_for_update()` method
2. **API Request**: The class fetches release data from Codeberg
3. **Version Comparison**: Compares your plugin version with the latest release
4. **Download**: Downloads the update (ZIP file or source archive)
5. **Installation**: Extracts and installs the update
6. **Activation**: Automatically reactivates the plugin

## Requirements

- WordPress 4.6+
- PHP 5.6+
- Codeberg account (for private repositories)
- cURL or allow_url_fopen enabled

## Troubleshooting

### Update Not Showing

1. Verify the release exists on Codeberg
2. Check that the tag name starts with 'v' (e.g., `v1.0.0`)
3. Ensure the version in your plugin header is lower than the release version
4. Clear WordPress transients: `delete_site_transient('update_plugins')`

### Private Repository Authentication Failed

1. Verify your token has the correct permissions
2. Ensure the token is correctly entered in the configuration
3. Check that the repository URL is correct

## Security Notes

- **Never hardcode tokens** in your plugin files. Consider using WordPress options or environment variables.
- For public repositories, you don't need a token.
- The token is only used for API requests and is transmitted via HTTPS.

## Example Plugin Header

Make sure your main plugin file has a proper header:

```php
<?php
/**
 * Plugin Name: My Awesome Plugin
 * Description: A great plugin that does awesome things
 * Version: 1.0.0
 * Author: Your Name
 * License: GPL v2 or later
 */
```

## Contributing

Contributions are welcome! Feel free to:
- Report bugs
- Suggest enhancements
- Submit pull requests

## License

This code is released under the GPL v2 (or later) license.

## Support

For issues with the updater class, please open an issue on the repository.

---

**Made with ❤️ for the WordPress community**
