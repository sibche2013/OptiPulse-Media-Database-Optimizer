=== Smart Media Manager ===
Contributors: aminarjmand
Donate link: https://aminarjmand.com/
Tags: media manager, image optimization, avif, fancybox, lightbox
Requires at least: 5.0
Tested up to: 7.1
Stable tag: 3.2
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A complete WordPress image optimization toolkit: Auto WebP/AVIF conversion, size and quality control, and a professional Fancybox lightbox.

== Description ==

Smart Media Manager is your all-in-one solution for optimizing WordPress media. 

**Key Features:**
*   **Next-Gen Formats:** Automatically convert uploaded JPEG and PNG images to WebP or AVIF formats to drastically reduce file sizes without losing quality.
*   **Size Management:** Disable default WordPress image sizes that you don't need, saving server disk space.
*   **Quality Control:** Take full control over the compression quality for your JPEG, PNG, WebP, and AVIF images.
*   **Image Fallback:** Automatically fallback to the original full-size image if a requested thumbnail size is missing, preventing 404 broken images on your site.
*   **Fancybox Integration:** Automatically turns all your WordPress and Elementor image galleries into beautiful, responsive Fancybox sliders.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/smart-media-manager` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to Media -> Smart Media Manager to configure the settings.

== Frequently Asked Questions ==

= Does the image conversion support all servers? =
The plugin checks if your server supports WebP and AVIF via GD or Imagick. If supported, the options will be available in the settings panel.

= Does it affect already uploaded images? =
No, the WebP/AVIF conversion applies to newly uploaded images only.

== Changelog ==

= 3.1 =
* First release on the WordPress plugin repository.
* Full integration with OOP, Security improvements, and UI enhancements.