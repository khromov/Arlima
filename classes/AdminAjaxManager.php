<?php

/**
 * Class that has all wp ajax functions used by this plugin. Important that you don't use closures
 * or any other php features that isn't available in php 5.2 in this file
 *
 * @todo: Remove all muting of possibly incorrect ajax requests
 * @todo: Move HTML code to other files
 * @package Arlima
 * @since 2.0
 */
class Arlima_AdminAjaxManager
{

    /**
     * @var Arlima_Plugin
     */
    private $arlima_plugin;

    /**
     * @var Arlima_ListFactory
     */
    private $factory;

    /**
     * @param Arlima_Plugin $arlima_plugin
     */
    public function __construct($arlima_plugin)
    {
        $this->arlima_plugin = $arlima_plugin;
        $this->factory = new Arlima_ListFactory();
    }

    /**
     * Setup all ajax functions
     */
    public function initActions()
    {
        add_action('wp_ajax_arlima_query_posts', array($this, 'queryPosts'));
        add_action('wp_ajax_arlima_get_scissors', array($this, 'getScissors'));
        add_action('wp_ajax_arlima_get_attached_images', array($this, 'getAttachedImages'));
        add_action('wp_ajax_arlima_get_post', array($this, 'getPost'));
        add_action('wp_ajax_arlima_add_list_widget', array($this, 'addListWidget'));
        add_action('wp_ajax_arlima_check_for_later_version', array($this, 'checkForLaterVersion'));
        add_action('wp_ajax_arlima_save_list', array($this, 'saveList'));
        add_action('wp_ajax_arlima_prepend_article', array($this, 'prependArticle'));
        add_action('wp_ajax_arlima_save_list_setup', array($this, 'saveListSetup'));
        add_action('wp_ajax_arlima_get_list_setup', array($this, 'getListSetup'));
        add_action('wp_ajax_arlima_upload', array($this, 'upload'));
        add_action('wp_ajax_arlima_print_custom_templates', array($this, 'printCustomTemplates'));
        add_action('wp_ajax_arlima_duplicate_image', array($this, 'duplicateImage'));
        add_action('wp_ajax_arlima_import_arlima_list', array($this, 'importList'));
    }

    /**
     * Import an external arlima list or RSS feed
     */
    function importList()
    {
        check_ajax_referer('arlima-nonce');
        $import_manager = new Arlima_ImportManager($this->arlima_plugin);
        try {
            $list = $import_manager->importList($_POST['url']);
            Arlima_ImportManager::displayImportedList($_POST['url'], $list['title']);
        } catch (Exception $e) {
            echo '<div class="error">' . $e->getMessage() . '</div>';
        }
        die;
    }

    /**
     * Make copy of an wordpress attachment
     */
    function duplicateImage()
    {

        //make sure the user came from this file
        check_ajax_referer('arlima-nonce');

        if ( !function_exists('wp_generate_attachment_metadata') ) {
            require_once(ABSPATH . "wp-admin" . '/includes/image.php');
            require_once(ABSPATH . "wp-admin" . '/includes/file.php');
            require_once(ABSPATH . "wp-admin" . '/includes/media.php');
        }

        $attachment_id = intval($_POST['attachid']);
        if ( $attachment_id ) {

            $url = wp_get_attachment_url($attachment_id);
            ;
            $tmp = download_url($url);
            $file_array = array(
                'name' => basename($url),
                'tmp_name' => $tmp
            );

            // Check for download errors
            if ( is_wp_error($tmp) ) {
                @unlink($file_array['tmp_name']);
                return $tmp;
            }

            $attach_id = media_handle_sideload($file_array, 0);

            // Check for handle sideload errors.
            if ( is_wp_error($attach_id) ) {
                @unlink($file_array['tmp_name']);
                return $attach_id;
            }

            echo json_encode(
                array(
                    'attach_id' => $attach_id,
                    'html' => wp_get_attachment_image($attach_id, 'large'),
                    'error' => false
                )
            );
        }

        die();
    }

    /**
     * Get arlima templates
     */
    function printCustomTemplates()
    {
        $templates = array(
            array(
                'name' => 'Full featured teaser',
                'title' => 'Lorem te ipusm dolor sit amet',
                'text' => '<p><span class="teaser-entryword">Lorem te ipsum</span> dolor sit amet anno del torro.</p>',
                'url' => 'http://google.com/',
                'title_fontsize' => 20,
                'options' => array(
                    'streamer' => '1',
                    'streamer_type' => 'text',
                    'streamer_color' => '3399ff', // Important, no #-sign
                    'streamer_image' => '',
                    'streamer_content' => 'Wild thing!',
                    'pre_title' => 'Demo:'
                )
            ),
            array(
                'name' => 'Empty teaser',
                'title' => '',
                'text' => '',
                'url' => ''
            )
        );

        // Make it possible for theme to override templates
        $templates = apply_filters('arlima_teaser_templates', $templates);

        ob_start();
        ?>
    <table class="widefat">
        <thead>
        <tr>
            <th>&nbsp;</th>
            <th><?php _e('Title', 'arlima') ?></th>
        </tr>
        </thead>
        <tfoot>
        <tr>
            <th>&nbsp;</th>
            <th><?php _e('Title', 'arlima') ?></th>
        </tr>
        </tfoot>
        <tbody>
            <?php $i = 0; foreach ($templates as $template): $i++; ?>
        <tr>
            <td>
                <li id="dragger_template_<?php echo $i; ?>" class="dragger listitem" style="z-index: 49;">
                    <div>
                        <span class="arlima-listitem-title"><img
                                src="<?php echo ARLIMA_PLUGIN_URL . '/images/arrow.png'; ?>" class="handle" alt="move"
                                height="16" width="16"/></span>
                        <img class="arlima-listitem-remove" alt="remove"
                             src="<?php echo ARLIMA_PLUGIN_URL . '/images/close-icon.png'; ?>"/>
                    </div>
                </li>
            </td>
            <td><?php echo $template['name']; ?></td>
        </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
        $html = ob_get_contents();
        ob_end_clean();
        $data = array("html" => $html, "articles" => $templates);
        echo json_encode($data);
        die();
    }

    /**
     * Upload an image
     */
    function upload()
    {

        //make sure the user came from this file
        check_ajax_referer('arlima-nonce');

        if ( !function_exists('wp_generate_attachment_metadata') ) {
            require_once(ABSPATH . "wp-admin" . '/includes/image.php');
            require_once(ABSPATH . "wp-admin" . '/includes/file.php');
            require_once(ABSPATH . "wp-admin" . '/includes/media.php');
        }

        $post_id = intval($_POST['postid']);

        // image file from user's desktop
        if ( !empty($_FILES) ) {
            foreach ($_FILES as $file => $array) {

                if ( $_FILES[$file]['error'] !== UPLOAD_ERR_OK ) {
                    return 'upload error: ' . $_FILES[$file]['error'];
                }
                if ( is_numeric($post_id) ) {
                    $attach_id = media_handle_upload($file, $post_id);
                } else {
                    $upload = wp_handle_upload($_FILES[$file], array('test_form' => false));
                    if ( !isset($upload['error']) && isset($upload['file']) ) {
                        $wp_filetype = wp_check_filetype(basename($upload['file']), null);
                        $attachment = array(
                            'post_mime_type' => $wp_filetype['type'],
                            'post_title' => preg_replace('/\.[^.]+$/', '', basename($upload['file'])),
                            'post_content' => '',
                            'post_status' => 'inherit'
                        );
                        $attach_id = wp_insert_attachment($attachment, $upload['file']);
                        $attach_data = wp_generate_attachment_metadata($attach_id, $upload['file']);
                        wp_update_attachment_metadata($attach_id, $attach_data);
                    }
                }
            }

        } // image from the web
        elseif ( !empty ($_POST['imgurl']) ) {

            if ( is_numeric($post_id) ) {

                media_sideload_image(urldecode($_POST['imgurl']), $post_id, '');

                $attachments = get_posts(
                    array(
                        'post_type' => 'attachment',
                        'number_posts' => 1,
                        'post_status' => null,
                        'post_parent' => $post_id,
                        'orderby' => 'post_date',
                        'order' => 'DESC',
                    )
                );

                $attach_id = $attachments[0]->ID;

            } else {

                $url = $_POST['imgurl'];
                $tmp = download_url($url);
                $file_array = array(
                    'name' => basename($url),
                    'tmp_name' => $tmp
                );

                /* @var WP_Error|string $tmp */
                // Check for download errors
                if ( is_wp_error($tmp) ) {
                    @unlink($file_array['tmp_name']);
                    die(json_encode(array('error' => $tmp->get_error_message())));
                }

                $attach_id = media_handle_sideload($file_array, 0);

                // Check for handle sideload errors.
                if ( is_wp_error($attach_id) ) {
                    @unlink($file_array['tmp_name']);
                    die(json_encode(array('error' => $attach_id->error_message())));
                }
            }
        } else {
            die(json_encode(array('error' => 'no file')));
        }

        if ( empty($attach_id) ) {
            die(json_encode(array('error' => 'no attach_id')));
        }

        echo json_encode(
            array('attach_id' => $attach_id, 'html' => wp_get_attachment_image($attach_id, 'default'), 'error' => false)
        );
        die();
    }

    /**
     * Get the list setup for currently logged in user
     */
    function getListSetup()
    {
        global $current_user;
        get_currentuserinfo();

        //make sure the user came from this file
        check_ajax_referer('arlima-nonce');

        $setup = get_user_meta($current_user->ID, 'arlima-list-setup', true);
        if ( $setup ) {
            echo json_encode($setup);
        }
        die();
    }

    /**
     * Saves the user setup (lists to load on startup and their position and size)
     */
    function saveListSetup()
    {
        global $current_user;
        get_currentuserinfo();

        //make sure the user came from this file
        check_ajax_referer('arlima-nonce');

        $lists = isset($_POST['lists']) ? $_POST['lists'] : null;

        if ( $lists ) {
            update_user_meta($current_user->ID, 'arlima-list-setup', $lists);
        } else {
            delete_user_meta($current_user->ID, 'arlima-list-setup');
        }
        die();
    }

    /**
     * Prepend an article to the top of a list
     */
    function prependArticle()
    {
        global $current_user, $post;
        get_currentuserinfo();

        //make sure the user came from this file
        check_ajax_referer('arlima-nonce');

        $list_id = isset($_POST['alid']) ? intval($_POST['alid']) : false;
        $post_id = isset($_POST['postid']) ? intval($_POST['postid']) : false;

        if ( $list_id && $post_id ) {

            $post = get_post($post_id);
            setup_postdata($post);
            $GLOBALS['post'] = $post; // Soemhting is removing post from global, even though we call setup_postdata

            if ( function_exists('vk_get_preamble') ) {
                $text = vk_get_preamble();
            } else {
                // weird, "get_the_excerpt" should return the manual excerpt but does not seem to do this in the admin context
                $text = !empty($post->post_excerpt) ? $post->post_excerpt : get_the_excerpt();
            }

            if ( stristr($text, '<p>') === false ) {
                $text = '<p>' . $text . '</p>';
            }

            $article = array(
                'title' => $post->post_title,
                'text' => $text,
                'url' => get_permalink($post->ID),
                'post_id' => $post->ID,
                'title_fontsize' => 24
            );

            if ( function_exists('has_post_thumbnail') && has_post_thumbnail() ) {
                $attach_id = get_post_thumbnail_id($post->ID, 'large');
                $article['image'] = wp_get_attachment_url($attach_id);
                $article['image_options'] = array(
                    'html' => get_the_post_thumbnail(
                        $post->ID,
                        'large',
                        array('class' => 'aligncenter')
                    ),
                    'url' => wp_get_attachment_url($attach_id),
                    'attach_id' => $attach_id,
                    'size' => 'full',
                    'alignment' => 'aligncenter'
                );
            }

            $list = $this->factory->loadList($list_id, false, true);
            $articles = $list->getArticles();
            array_unshift($articles, $article);

            $this->saveAndOutputNewListVersion($list, $articles);
        }
        die();
    }

    /**
     * Save a new version of a list
     */
    function saveList()
    {
        check_ajax_referer('arlima-nonce');
        $list_id = isset($_POST['alid']) ? intval($_POST['alid']) : false;

        if ( $list_id ) {
            $articles = !empty($_POST['articles']) ? $_POST['articles'] : array();
            $this->saveAndOutputNewListVersion($list_id, $articles, isset($_POST['preview']));
        }

        die();
    }

    /**
     * @param Arlima_List|int $list_id
     * @param $articles
     * @param bool $preview
     */
    private function saveAndOutputNewListVersion($list_id, $articles, $preview = false)
    {
        if ( $list_id instanceof Arlima_List ) {
            $list = $list_id;
        } else {
            $list = $this->factory->loadList($list_id);
        }

        $this->factory->saveNewListVersion($list, $articles, get_current_user_id(), $preview);

        // Reload list to get latest version
        $list = $this->factory->loadList($list_id, false, true);

        echo json_encode(
            array(
                'version' => $list->getVersion(),
                'versioninfo' => $list->getVersionInfo(),
                'versions' => $list->getVersions()
            )
        );

        // custom hook that other plugins can add actions to.
        do_action('arlima_save_list', $list);
    }

    /**
     * Checks if there is a later version of the list that's about to be saved
     */
    function checkForLaterVersion()
    {
        check_ajax_referer('arlima-nonce');

        $list_id = isset($_POST['alid']) ? (int)$_POST['alid'] : false;
        $version = isset($_POST['version']) ? (int)$_POST['version'] : false;

        if ( $list_id && $version ) {
            $list = $this->factory->loadList($list_id);
            if ( $list->getVersionAttribute('id') > $version ) {
                echo json_encode(
                    array(
                        'version' => $list->getVersion(),
                        'versioninfo' => $list->getVersionInfo()
                    )
                );
                die;
            }
        }

        echo json_encode(array('version' => false));
        die;
    }

    /**
     * Fetches an arlima list and outputs it in widget form
     */
    function addListWidget()
    {

        check_ajax_referer('arlima-nonce');

        $list_id = isset($_POST['alid']) ? trim($_POST['alid']) : null;
        $version = isset($_POST['version']) && is_numeric($_POST['version']) ? (int)$_POST['version'] : false;

        if ( is_numeric($list_id) ) {
            $list = $this->factory->loadList($list_id, $version, true);
            $this->loadListWidgets($list);
        } // Probably url referring to an imported list
        elseif ( $list_id ) {
            try {
                $import_manager = new Arlima_ImportManager($this->arlima_plugin);
                $list = $import_manager->loadList($list_id);
                $this->loadImportedListWidget($list);
            } catch (Exception $e) {
                header('HTTP/1.1 500 Internal Server Error');
                echo json_encode(array('error' => $e->getMessage()));
            }
        }

        die();
    }

    /**
     * Outputs a list in json format
     * @param Arlima_List $list
     */
    private function loadListWidgets($list)
    {

        ob_start();
        ?>
    <div class="arlima-list-header">
        <span>
            <a href="#" class="arlima-list-container-remove">
                <img src="<?php echo ARLIMA_PLUGIN_URL . '/images/close-icon.png'; ?>"/>
            </a>
            <?php echo $list->getTitle(); ?>
        </span>
    </div>
    <div class="arlima-list-scroller">
        <ul class="arlima-list" id="arlima-list-<?php echo $list->id(); ?>"></ul>
    </div>
    <div class="arlima-list-footer">
        <div class="arlima-list-footer-buttons">
            <a class="arlima-preview-list" id="arlima-preview-list-<?php echo $list->id(); ?>" title="Granska">
                <img src="<?php echo ARLIMA_PLUGIN_URL . '/images/preview-icon.png'; ?>"/>
            </a>
            <a class="arlima-save-list" id="arlima-save-list-<?php echo $list->id(); ?>" title="Publicera"
               style="display:none;">
                <img src="<?php echo ARLIMA_PLUGIN_URL . '/images/save-icon.png'; ?>"/>
                <a class="arlima-refresh-list" id="arlima-refresh-list-<?php echo $list->id(); ?>" alt="Uppdatera"
                   title="Uppdatera">
                    <img src="<?php echo ARLIMA_PLUGIN_URL . '/images/reload-icon-16.png'; ?>"/>
                </a>
                <img src="<?php echo ARLIMA_PLUGIN_URL . '/images/ajax-loader-trans.gif'; ?>" class="ajax-loader"/>
            </a>

            <div class="arlima-list-version"><span class="arlima-list-version-select"><select
                    class="arlima-list-version-ddl" name="list-version"></select></span><span
                    class="arlima-list-version-info tooltip"></span></div>
        </div>
        <!-- .arlima-list-footer-buttons -->
    </div><!-- .arlima-list-footer -->
    <input type="hidden" name="arlima-list-id" id="arlima-list-id-<?php echo $list->id(); ?>" class="arlima-list-id"
           value="<?php echo $list->id(); ?>"/>
    <input type="hidden" name="arlima-list-previewpage" id="arlima-list-previewpage-<?php echo $list->id(); ?>"
           class="arlima-list-previewpage" value="<?php echo $list->loadPreviewPageURL() ?>"/>
    <input type="hidden" name="arlima-version-id" id="arlima-version-id-<?php echo $list->id(); ?>"
           class="arlima-version-id" value="<?php echo $list->getVersionAttribute('id'); ?>"/>
    <input type="hidden" name="arlima-list-previewtemplate" id="arlima-list-previewtemplate-<?php echo $list->id(); ?>"
           class="arlima-list-previewtemplate" value="<?php echo $list->getOption('previewtemplate'); ?>"/>
    <?php
        $html = ob_get_contents();
        ob_end_clean();
        $data = array(
            'html' => $html,
            'articles' => $list->getArticles(),
            'version' => $list->getVersion(),
            'versioninfo' => $list->getVersionInfo(),
            'versions' => $list->getVersions(),
            'title_element' => $list->getTitleElement(),
            'is_imported' => 0,
            'exists' => $list->exists()
        );
        echo json_encode($data);
    }

    /**
     * Outputs an imported list
     * @param Arlima_List $list
     */
    private function loadImportedListWidget($list)
    {
        $version_info = sprintf(
            __('Last modified %s a go', 'arlima'),
            human_time_diff($list->getVersionAttribute('created'))
        );
        ob_start();
        ?>
    <div class="arlima-list-header">
        <a href="#" class="arlima-list-container-remove">
            <img src="<?php echo ARLIMA_PLUGIN_URL . '/images/close-icon.png'; ?>"/>
        </a>
            <span>
                <?php echo $list->getTitle(); ?>
            </span>
    </div>
    <div class="arlima-list-scroller">
        <ul class="arlima-list imported" id="arlima-list-<?php echo $list->id(); ?>"></ul>
        <!-- .arlima-list -->
    </div>
    <div class="arlima-list-footer">
        <div class="arlima-list-footer-buttons">
                <span class="last-mod arlima-list-version-info">
                <?php echo $version_info; ?>
                </span>
            <a class="arlima-refresh-list" id="arlima-refresh-list-<?php echo $list->id(); ?>" alt="Uppdatera"
               title="Uppdatera">
                <img src="<?php echo ARLIMA_PLUGIN_URL . '/images/reload-icon-16.png'; ?>"/>
            </a>
            <img src="<?php echo ARLIMA_PLUGIN_URL . '/images/ajax-loader-trans.gif'; ?>" class="ajax-loader"/>
        </div>
        <!-- .arlima-list-footer-buttons -->
    </div>
    <input type="hidden" name="arlima-list-id" id="arlima-list-id-<?php echo $list->id(); ?>" class="arlima-list-id"
           value="<?php echo $list->id; ?>"/>
    <input type="hidden" name="arlima-version-id" id="arlima-version-id-<?php echo $list->id(); ?>"
           class="arlima-version-id" value="<?php echo $list->getVersionAttribute('id'); ?>"/>
    <?php
        $html = ob_get_contents();
        ob_end_clean();
        $data = array(
            'html' => $html,
            'articles' => $list->getArticles(),
            'version' => 0,
            'versioninfo' => $version_info,
            'versions' => array(),
            'is_imported' => 1,
            'exists' => $list->exists()
        );
        echo json_encode($data);
    }

    /**
     * Get a wordpress post in json format
     */
    function getPost()
    {

        //make sure the user came from this file
        check_ajax_referer('arlima-nonce');

        $post_id = intval($_POST['postid']);
        $post = get_post($post_id);

        if ( $post->post_type == 'post' && $post->post_status != 'deleted' && $post->post_status != 'trash' ) {
            $post->url = get_permalink($post->ID);
            $post->publish_date = strtotime($post->post_date_gmt);
            echo json_encode((array)$post);
        }

        die();
    }

    /**
     * Get post attachments
     */
    function getAttachedImages()
    {

        check_ajax_referer('arlima-nonce');

        $post_id = intval($_POST['postid']);

        $args = array(
            'order' => 'DESC',
            'post_parent' => $post_id,
            'post_status' => null,
            'post_type' => 'attachment',
            'post_mime_type' => 'image'
        );

        $images = array();

        $attachments = get_children($args);

        if ( $attachments ) {
            foreach ($attachments as $attachment) {
                $images[] = array(
                    'attach_id' => $attachment->ID,
                    'thumb' => wp_get_attachment_image($attachment->ID, 'thumbnail'),
                    'large' => wp_get_attachment_image($attachment->ID, 'large')
                );
            }
        }

        echo json_encode($images);
        die();
    }

    function getScissors()
    {

        check_ajax_referer('arlima-nonce');

        $attachment_id = $_POST['attachment_id'];

        if ( Arlima_Plugin::isScissorsInstalled() ) {

            $scissors_output = '';
            $thumb = get_post($attachment_id);
            $scissors_output = scissors_media_meta($scissors_output, $thumb);

            if ( !empty($scissors_output) ) {
                echo $scissors_output;
            }
        }
        die();
    }

    /**
     * Search for posts
     */
    function queryPosts()
    {

        //make sure the user came from this file
        check_ajax_referer('arlima-nonce');

        $catid = !empty($_POST['catid']) ? $_POST['catid'] : false;
        $search = !empty($_POST['search']) ? $_POST['search'] : false;
        $author = !empty($_POST['author']) ? $_POST['author'] : false;
        $offset = !empty($_POST['offset']) && is_numeric($_POST['offset']) ? (int)$_POST['offset'] : 0;

        if ( $catid ) {
            $args['cat'] = $catid;
        }
        if ( $author ) {
            $args['author'] = $author;
        }

        $args['s'] = '';
        if ( $search ) {
            if ( is_numeric($search) ) {
                $args['p'] = $search;
            } else {
                $args['s'] = $search;
            }
        }

        $args['numberposts'] = 10;
        if ( $offset ) {
            $args['offset'] = $offset;
        }

        $args['post_status'] = array('publish', 'future');
        $args['post_type'] = array('post', 'usernews');

        // Possibly modified by other plugins or the theme (take a look at readme.txt for more info)
        $args = Arlima_PostSearchModifier::filterWPQuery($args, $_POST);

        $this->iteratePosts(query_posts($args), $offset);

        die();
    }

    /**
     * @param array $posts
     * @param int $offset
     */
    private function iteratePosts($posts, $offset = 0)
    {
        $articles = array();
        ob_start();
        ?>
    <table class="widefat">
        <thead>
        <tr>
            <th>&nbsp;</th>
            <th>Id</th>
            <th><?php _e('Title', 'arlima') ?></th>
            <th><?php _e('Author', 'arlima') ?></th>
            <th><?php _e('Date', 'arlima') ?></th>
        </tr>
        </thead>
        <tfoot>
        <tr>
            <th>
                <?php if ( $offset > 0 ) { ?> <a href="" alt="<?php echo (int)$offset - 10; ?>"
                                                 class="arlima-get-posts-paging">&laquo; <?php _e(
                'Previous',
                'arlima'
            ) ?></a> <?php } ?>
            </th>
            <th colspan="3"></th>
            <th style="text-align:right;">
                <?php if ( sizeof($posts) >= 10 ) { ?> <a href="" alt="<?php echo (int)$offset + 10; ?>"
                                                          class="arlima-get-posts-paging"><?php _e(
                'Next',
                'arlima'
            ) ?> &raquo;</a> <?php } ?>
            </th>
        </tr>
        </tfoot>
        <tbody>
            <?php foreach ($posts as $post):
            setup_postdata($post);
            $GLOBALS['post'] = $post; // Soemhting is removing post from global, even though we call setup_postdata

            if ( function_exists('vk_get_preamble') ) {
                $text = vk_get_preamble();
            } else {
                // weird, "get_the_excerpt" should return the manual excerpt but does not seem to do this in the admin context
                $text = !empty($post->post_excerpt) ? $post->post_excerpt : get_the_excerpt();
            }
            if ( stristr($text, '<p>') === false ) {
                $text = '<p>' . $text . '</p>';
            }

            $url = get_permalink($post->ID);
            $article = array(
                'post_id' => $post->ID,
                'title' => $post->post_title,
                'text' => $text,
                'url' => $url,
                'title_fontsize' => 24,
                'content' => apply_filters('the_content', $post->post_content),
                'publish_date' => strtotime($post->post_date_gmt)
            );

            if ( function_exists('has_post_thumbnail') && has_post_thumbnail($post->ID) ) {
                $attach_id = get_post_thumbnail_id($post->ID);
                $article['image'] = wp_get_attachment_url($attach_id);
                $article['image_options'] = array(
                    'html' => get_the_post_thumbnail($post->ID, 'large'),
                    'url' => wp_get_attachment_url($attach_id),
                    'attach_id' => $attach_id,
                    'size' => 'full',
                    'alignment' => 'aligncenter',
                    'connected' => true
                );
            }
            $articles[] = $article;
            ?>
        <tr>
            <td>
                <li id="dragger_<?php echo $post->ID; ?>" class="dragger listitem">
                    <div>
                        <span class="arlima-listitem-title"><img
                                src="<?php echo ARLIMA_PLUGIN_URL . '/images/arrow.png'; ?>" class="handle" alt="move"
                                height="16" width="16"/></span>
                        <img class="arlima-listitem-remove" alt="remove"
                             src="<?php echo ARLIMA_PLUGIN_URL . '/images/close-icon.png'; ?>"/>
                    </div>
                </li>
            </td>
            <td><?php echo $post->ID; ?></td>
            <td width="220"><?php edit_post_link(
                $post->post_title,
                '',
                '',
                $post->ID
            ); ?><?php if ( $post->post_status == 'future' ) {
                echo '<br /><em>(' . __(
                    'unpublished',
                    'arlima'
                ) . ')</em> ';
            } ?></td>
            <td><?php the_author_meta('user_login', $post->post_author); ?></td>
            <td><?php echo date('Y-m-d H:i', strtotime($post->post_date)); ?></td>
        </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
        $html = ob_get_contents();
        ob_end_clean();
        $data = array("html" => $html, "articles" => $articles);
        echo json_encode($data);
    }
}