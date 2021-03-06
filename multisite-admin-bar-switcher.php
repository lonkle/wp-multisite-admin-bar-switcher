<?php
/*
    Plugin Name: Multisite Admin bar Switcher
    Plugin URI: http://www.flynsarmy.com
    Description: Replaces the built in 'My Sites' drop down with a better layed out one
    Version: 1.4.0
    Author: Flyn San
    Author URI: http://www.flynsarmy.com/

    Copyright 2013  Flyn San  (email : flynsarmy@gmail.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

require_once __DIR__.'/mabs_admin_bar.php';

/**
 * Filter the admin bar class to instantiate.
 *
 * @since 3.1.0
 *
 * @param string $wp_admin_bar_class Admin bar class to use. Default 'WP_Admin_Bar'.
 */
add_filter('wp_admin_bar_class', function ($wp_admin_bar_class) {
    return 'MABS_Admin_Bar';
});

add_action('add_admin_bar_menus', function () {
    if (is_multisite()) {
        remove_action('admin_bar_menu', 'wp_admin_bar_site_menu', 30);
        remove_action('admin_bar_menu', 'wp_admin_bar_my_sites_menu', 20);
    }
});

function mabs_require_with($partial, $data)
{
    extract($data);
    ob_start();
    require $partial;
    return ob_get_clean();
}

// From http://wordpress.stackexchange.com/questions/16474/how-to-add-field-for-new-site-wide-option-on-network-settings-screen
add_action('network_admin_menu', function () {
    add_submenu_page(
        'settings.php',
        'Multisite Admin Bar Switcher',
        'Multisite Admin Bar Switcher',
        'manage_network_options',
        'mabs-settings',
        function () {
            echo mabs_require_with(dirname(__FILE__).'/partials/network-admin/settings.php', array(
                'options' => get_site_option('mabs'),
            ));
        }
    );
});

add_action('wp_ajax_clear_mabs_cache', function () {
    mabs_clear_cache();
    exit("Cache cleared.");
});

add_action('admin_bar_menu', function () {
    // No need to show MABS
    if (!is_multisite()) {
        return;
    }

    global $wp_admin_bar, $wpdb, $current_blog;

    //$wp_admin_bar->remove_node('my-sites');
    //$wp_admin_bar->remove_node('site-name');

    $current_user = wp_get_current_user();
    $bloginfo = mabs_convert_blog_fields($current_blog);

    // current site path
    if (is_network_admin()) {
        $blogname = __('Network');
        $url = get_home_url($current_blog->blog_id);
    } elseif (is_admin()) {
        $blogname = get_blog_option($current_blog->blog_id, "blogname");
        $url = get_home_url($current_blog->blog_id);
    } else {
        $blogname = get_blog_option($current_blog->blog_id, "blogname");
        $url = get_admin_url($current_blog->blog_id);
    }
    // Add top menu
    $wp_admin_bar->add_menu(array(
        'parent' => false,
        'id' => 'mabs',
        'title' => trim(__('My Sites:') . ' ' . apply_filters('mabs_blog_name', $blogname, $bloginfo)),
        'href' => $url,
    ));

    // Add 'Your Site'
    $url = get_admin_url($current_blog->blog_id);
    $wp_admin_bar->add_menu(array(
        'parent' => 'mabs',
        'id' => 'mabs_yoursite',
        'title' =>__('Your Site'),
        'href' => str_replace('/wp-admin/', '', $url)
    ));
    mabs_display_blog_pages($current_user, 'yoursite', $url, $current_blog);

    // Add 'Network'
    if (current_user_can('manage_network')) {
        // add network menu
        $url = network_admin_url();
        $wp_admin_bar->add_menu(array(
            'parent' => 'mabs',
            'id' => 'mabs_network',
            'title' =>__('Network'),
            'href' => $url,
        ));
        mabs_display_blog_pages($current_user, 'network', $url, $current_blog);
    }

    do_action('mabs_top_level_menus');

    // Add users' blogs
    mabs_display_blogs_for_user($current_user);
}, 40);

add_action('wp_enqueue_scripts', 'mabs_enqueue_assets');
add_action('admin_enqueue_scripts', 'mabs_enqueue_assets');
function mabs_enqueue_assets()
{
    if (!is_admin_bar_showing() || !is_user_logged_in() || mabs_site_count_below_minimum(wp_get_current_user())) {
        return;
    }

    wp_enqueue_style(
        'mabs_styles',
        plugins_url('assets/css/mabs_styles.css', __FILE__)
    );
    wp_enqueue_script(
        'mabs_site_filter',
        plugins_url('assets/js/mabs_site_filter.js', __FILE__),
        ['jquery'],
        '2014.09.25',
        true
    );
}

add_action('wpmu_new_blog', 'mabs_clear_cache');
add_action('wpmu_activate_blog', 'mabs_clear_cache');

add_action('add_user_to_blog', function ($user_id) {
    mabs_clear_user_cache($user_id);
}, 10, 1);
add_action('added_existing_user', function ($user_id) {
    mabs_clear_user_cache($user_id);
}, 10, 1);
add_action('remove_user_from_blog', function ($user_id, $blog_id) {
    mabs_clear_user_cache($user_id);
}, 10, 2);


function mabs_clear_cache()
{
    if (!is_user_logged_in()) {
        return;
    }

    global $wpdb;

    $wpdb->query("
        DELETE FROM ".$wpdb->base_prefix."sitemeta 
        WHERE meta_key LIKE '_site_transient_mabs_%'; 
    ");
}
function mabs_clear_user_cache($user_id)
{
    delete_site_transient('mabs_bloglist_'.$user_id);
    delete_site_transient('mabs_is_below_min_'.$user_id);
    delete_site_transient('mabs_admin_urls');
    delete_site_transient('mabs_bloglist_network');
}

/**
 * @param stdClass $user
 * @return bool
 */
function mabs_site_count_below_minimum($user)
{
    $cache = get_site_transient('mabs_site_count_'.$user->ID);

    if (!$cache) {
        $blogs = mabs_get_blog_list($user);

        $cache = sizeof($blogs);
        set_site_transient('mabs_site_count_'.$user->ID, $cache, apply_filters('mabs_cache_duration', 60*60*30));
    }

    return $cache < 5;
}

/**
 * Adds a blogs submenu items to the admin drop down menu.
 *
 * @param  stdClass $user A wordpress user
 * @param  integer  $id   site ID
 * @param  string   $url  '<url>/wp-admin/'
 * @param  stdClass $blog A wordpress blog site
 *
 * @return void
 */
function mabs_display_blog_pages($user, $id, $admin_url, $blog)
{
    global $wp_admin_bar;
    if ($id == 'network') {
        $pages = array(
            'dashboard'     => array('url' => 'index.php'),
            'sites'         => array('url' => 'sites.php'),
            'users'         => array('url' => 'users.php'),
            'themes'        => array('url' => 'themes.php'),
            'plugins'       => array('url' => 'plugins.php'),
            'settings'      => array('url' => 'settings.php'),
            'updates'       => array('url' => 'update-core.php'),
        );
    } else {
        $pages = array(
            'dashboard'     => array('url' => 'index.php'),
            'visit'         => array('url' => ''),
            'posts'         => array('url' => 'edit.php',           'permission' => 'edit_posts'),
            'media'         => array('url' => 'media.php',          'permission' => 'upload_files'),
            'links'         => array('url' => 'link-manager.php',   'permission' => 'manage_links'),
            'pages'         => array('url' => 'edit.php?post_type=page', 'permission' => 'edit_pages'),
            'comments'      => array('url' => 'edit-comments.php',  'permission' => 'edit_posts'),
            'appearance'    => array('url' => 'themes.php',         'permission' => 'switch_themes'),
            'plugins'       => array('url' => 'plugins.php',        'permission' => 'install_plugins'),
            'users'         => array('url' => 'users.php',          'permission' => 'list_users'),
            'tools'         => array('url' => 'tools.php',          'permission' => 'import'),
            'settings'      => array('url' => 'options-general.php','permission' => 'manage_options'),
        );
    }

    $pages = apply_filters('mabs_blog_pages', $pages, $id, $blog);

    foreach ($pages as $key => $details) {
        if ($key == "visit") {
            $wp_admin_bar->add_menu(array(
                'parent' => 'mabs_'.$id,
                'id' =>'mabs_'.$id.'_'.$key,
                'title'=>__('Visit Site'),
                'href'=>str_replace('wp-admin/', '', $admin_url),
                'meta' => array(
                    'target' => !empty($blog->external_site) ? '_blank' : '',
                ),
            ));
        } elseif (empty($details['permission']) || user_can($user->ID, $details['permission'])) {
            $wp_admin_bar->add_menu(array(
                'parent' => 'mabs_'.$id,
                'id' =>str_replace(' ', '_', 'mabs_'.$id.'_'.$key),
                'title'=> isset($details['title']) ? $details['title'] : __(ucfirst($key)),
                'href' => strpos($details['url'], 'http') !== false ? $details['url'] : $admin_url.$details['url'],
                'meta' => array(
                    'target' => !empty($blog->external_site) ? '_blank' : '',
                ),
            ));
        }
    }
}

function mabs_get_admin_url($userblog_id)
{
    $cache = get_site_transient('mabs_admin_urls');

    if (!is_array($cache)) {
        $cache = [];
    }

    if (!isset($cache[$userblog_id])) {
        $cache[$userblog_id] = get_admin_url($userblog_id);
        set_site_transient('mabs_admin_urls', $cache, apply_filters('mabs_cache_duration', 60*60*30));
    }

    return $cache[$userblog_id];
}

/**
 * Add the blog list under their respective letters
 *
 * @param  stdClass $user A wordpress user
 *
 * @return void
 */
function mabs_display_blogs_for_user($user)
{
    global $wp_admin_bar, $wpdb;

    // Add Filter field
    if (!mabs_site_count_below_minimum($user)) {
        $wp_admin_bar->add_menu(array(
            'parent' => 'mabs',
            'id'     => 'mabs_site_filter',
            'title'  => '<label for="mabs_site_filter_text">'. __('Filter My Sites', 'mabs') .'</label>' .
                '<input type="text" id="mabs_site_filter_text" autocomplete="off" placeholder="'.
                __('Search Sites', 'mabs') .
                '" />',
            'meta'   => array(
                'class' => 'hide-if-no-js'
            )
        ));
    }

    $blogs = mabs_get_blog_list($user);

    //Add letter submenus
    mabs_display_letters($blogs);

    // add menu item for each blog
    $i = 1;
    foreach ($blogs as $key => $blog) {
        $letter = mb_strtoupper(mb_substr($key, 0, 1));
        $site_parent = "mabs_".$letter."_letter";
        $admin_url = !empty($blog->external_site) ? $blog->adminurl : mabs_get_admin_url($blog->userblog_id);
        $href = !empty($blog->external_site) && $blog->external_site == 'network' ?
            ($admin_url . 'network/') :
            $admin_url;

        //Add the site
        $wp_admin_bar->add_menu(array(
            'parent' => $site_parent,
            'id' => 'mabs_'.$letter.$i,
            'title' => apply_filters('mabs_blog_name', $blog->blogname, $blog),
            'href' => $href,
            'meta' => array(
                'class' => 'mabs_blog',
                'target' => !empty($blog->external_site) ? '_blank' : '',
            ),
        ));

        //Add site submenu options
        mabs_display_blog_pages($user, $letter.$i, $admin_url, $blog);

        $i++;
    }
}

/**
 * Adds a letter submenu for each blog to sit in
 *
 * @param  array  $blogs List of blogs
 *
 * @return void
 */
function mabs_display_letters(array $blogs)
{
    global $wp_admin_bar;

    $letters = array();
    foreach ($blogs as $key => $blog) {
        $letters[ mb_strtoupper(mb_substr($key, 0, 1)) ] = '';
    }

    foreach (array_keys($letters) as $letter) {
        $wp_admin_bar->add_menu(array(
            'parent' => 'mabs',
            'id' => 'mabs_'.$letter.'_letter',
            'title' => __($letter),
            'meta' => array(
                'class' => 'mabs_letter',
            ),
        ));
    }
}

/**
 * Returns an alphabetically sorted array of blogs
 *
 * @param  stdClass $user Current user object
 *
 * @return array       Alphabetically sorted array of blogs
 *      stdClass Object
 *      (
 *          [userblog_id] => 1
 *          [blogname] => My Blog
 *          [domain] => myblog.localhost.com
 *          [path] => /
 *          [site_id] => 1
 *          [siteurl] => http://myblog.localhost.com
 *          [archived] => 0
 *          [spam] => 0
 *          [deleted] => 0
 *      )
 */
$mabs_user_blog_list = array();
function mabs_get_blog_list($user)
{
    global $wp_admin_bar;
    global $mabs_user_blog_list;

    // Only do this once
    if (!isset($mabs_user_blog_list[$user->ID])) {
        // Try to retrieve sorted list from cache
        $cache = get_site_transient('mabs_bloglist_'.$user->ID);
        if ($cache) {
            $sorted = $mabs_user_blog_list[$user->ID] = $cache;
        }

        if (empty($mabs_user_blog_list[$user->ID])) {
            if (user_can($user, 'manage_network')) {
                $unsorted_list = mabs_get_blogs_of_network();
            } else {
                $unsorted_list = mabs_get_blogs_of_user($user->ID);
            }

            $unsorted_list = apply_filters('mabs_unsorted_sites_list', $unsorted_list);

            $sorted = array();

            // Add blogname to key list. Also add a number so we
            // are certain keys are unique
            foreach ($unsorted_list as $key => $blog) {
                $sorted[ strtoupper($blog->blogname) . $key ] = $blog;
            }

            ksort($sorted);

            // Cache sorted list for 30 mins
            set_site_transient('mabs_bloglist_'.$user->ID, $sorted, apply_filters('mabs_cache_duration', 60*60*30));

            $mabs_user_blog_list[$user->ID] = $sorted;
        }
    } else {
        $sorted = $mabs_user_blog_list[$user->ID];
    }

    return $sorted;
}

function mabs_get_blogs_of_user($user_id)
{
    $cache = get_site_transient('mabs_user_blogs_'.$user_id);
    if (!$cache) {
        $cache = get_blogs_of_user($user_id);
        set_site_transient('mabs_user_blogs_'.$user_id, $cache, apply_filters('mabs_cache_duration', 60*60*30));
    }

    return $cache;
}

/**
 * @return array of stdClass
 *      (
 *          [userblog_id] => 1
 *          [blogname] => My Blog
 *          [domain] => myblog.localhost.com
 *          [path] => /
 *          [site_id] => 1
 *          [siteurl] => http://myblog.localhost.com
 *          [archived] => 0
 *          [spam] => 0
 *          [deleted] => 0
 *      )
 */
function mabs_get_blogs_of_network()
{
    $cache = get_site_transient('mabs_bloglist_network');
    if (!$cache) {
        // This method returns different info than get_blogs_of_user(). So make it the same
        $blog_list = get_sites(array('number'=>9999));
        $unsorted_list = array();

        foreach ($blog_list as $id => $info) {
            $userblog_id = intval($info->blog_id);
            $unsorted_list[$userblog_id] = mabs_convert_blog_fields($info);
        }

        $cache = $unsorted_list;
        set_site_transient('mabs_bloglist_network', $cache, apply_filters('mabs_cache_duration', 60*60*30));
    }

    return $cache;
}

/**
 * The get_sites() method returns an object with different fields to
 * get_blogs_of_user(). This method converts fields in the former to the latter.
 *
 * @param  stdClass $fields
 *           [blog_id] => 1
 *           [site_id] => 1
 *           [domain] => myblog.localhost.com
 *           [path] => /
 *           [registered] => 2012-04-17 02:42:46
 *           [last_updated] => 2014-09-24 00:46:15
 *           [public] => 1
 *           [archived] => 0
 *           [mature] => 0
 *           [spam] => 0
 *           [deleted] => 0
 *           [lang_id] => 0
 *
 * @return stdClass
 *      (
 *          [userblog_id] => 1
 *          [blogname] => My Blog
 *          [domain] => myblog.localhost.com
 *          [path] => /
 *          [site_id] => 1
 *          [siteurl] => http://myblog.localhost.com
 *          [archived] => 0
 *          [spam] => 0
 *          [deleted] => 0
 *      )
 */
function mabs_convert_blog_fields($fields)
{
    // Make sure we're always working with an array
    $fields = (array)$fields;
    $userblog_id = intval($fields['blog_id']);

    return (object)array(
        'userblog_id' => $userblog_id,
        'blogname' => get_blog_option($fields['blog_id'], 'blogname'),
        'domain' => $fields['domain'],
        'path' => $fields['path'],
        'site_id' => $fields['site_id'],
        'siteurl' => get_blog_option($fields['blog_id'], 'siteurl'),
        'archived' => intval($fields['archived']),
        'spam' => intval($fields['spam']),
        'deleted' => intval($fields['deleted']),
    );
}
