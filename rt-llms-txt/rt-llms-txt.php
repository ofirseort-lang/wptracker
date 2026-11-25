<?php
/**
 * Plugin Name: RT LLMs.txt
 * Plugin URI:  https://realtimecollege.co.il
 * Description: Serves /llms.txt and /llms-full.txt generated from published WordPress content, rebuilt nightly. See docs/rt-llms-txt/SRS.md.
 * Version:     0.2.0
 * Author:      Real Time College
 * License:     GPL-2.0-or-later
 * Text Domain: rt-llms-txt
 * Requires PHP: 7.4
 * Requires at least: 6.0
 *
 * Changelog:
 *   0.2.0 - Settings page (Settings -> LLMs.txt: title, summary, facts, post
 *           types, run hour, cache lifetime, description lengths, status, run
 *           log, preview, regenerate) and per-post meta box (exclude, featured).
 *   0.2.0 - Skeleton: serving (FR-1..FR-6), builder, cache, nightly job and
 *           immediate removal (FR-23, FR-24, FR-24a), meta-tag fetcher (FR-10).
 *           Not yet built: setup wizard, settings page, domain taxonomy,
 *           facts (FR-33), conflict detection, tests.
 */

defined( 'ABSPATH' ) || exit;

define( 'RT_LLMS_VERSION', '0.2.0' );
define( 'RT_LLMS_DIR', plugin_dir_path( __FILE__ ) );

require_once RT_LLMS_DIR . 'includes/class-rt-llms-settings.php';
require_once RT_LLMS_DIR . 'includes/class-rt-llms-fetcher.php';
require_once RT_LLMS_DIR . 'includes/class-rt-llms-builder.php';
require_once RT_LLMS_DIR . 'includes/class-rt-llms-cache.php';
require_once RT_LLMS_DIR . 'includes/class-rt-llms-server.php';
require_once RT_LLMS_DIR . 'includes/class-rt-llms-scheduler.php';
require_once RT_LLMS_DIR . 'includes/class-rt-llms-admin.php';
require_once RT_LLMS_DIR . 'includes/class-rt-llms-metabox.php';

RT_LLMS_Server::init();
RT_LLMS_Scheduler::init();

if ( is_admin() ) {
	RT_LLMS_Admin::init();
	RT_LLMS_Metabox::init();
}

register_activation_hook( __FILE__, array( 'RT_LLMS_Scheduler', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'RT_LLMS_Scheduler', 'deactivate' ) );
