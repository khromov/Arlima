<?php

/**
 * Class that can render an Arlima article list using jQueryTmpl. The class
 * uses templates available in the path given on construct, if template not
 * found it falls back on templates available in this plugin directory (arlima/templates)
 *
 * @package Arlima
 * @since 2.0
 */
class Arlima_ListTemplateRenderer extends Arlima_AbstractListRenderingManager
{
    /**
     * @var array
     */
    private $template_resolver = array();

    /**
     * @var Arlima_TemplateObjectCreator
     */
    private $template_obj_creator;

    /**
     * Current unix time
     * @var int
     */
    private $now;

    /**
     * @var array
     */
    private static $preloaded_templates = array();

    /**
     * @var Mustache_Template
     */
    protected $default_template_obj = null;

    /**
     * @var string
     */
    protected $default_template_name = null;

    /**
     * @var Mustache_Engine
     */
    protected $template_engine = null;

    /**
     * Class constructor
     * @param Arlima_List|stdClass $list
     * @param string $template_path - Optional path to directory where templates should exists
     */
    function __construct($list, $template_path = null)
    {
        $this->now = time();
        $this->template_resolver = new Arlima_TemplatePathResolver(array($template_path));
        $this->template_engine = new Mustache_Engine();
        parent::__construct($list);
    }

    /**
     * Prepares the template object creator. All callbacks must be added to this class
     * before running this function. A callback added after this function is called
     * will not be triggered
     */
    protected function setupObjectCreator()
    {
        $this->template_obj_creator = new Arlima_TemplateObjectCreator();
        $this->template_obj_creator->setList($this->getList());
        if ( !empty($this->list->options['before_title']) ) {
            $this->template_obj_creator->setBeforeTitleHtml($this->list->options['before_title']);
            $this->template_obj_creator->setAfterTitleHtml($this->list->options['after_title']);
        }

        $this->template_obj_creator->setImgSize($this->img_size_name);
        $this->template_obj_creator->setArticleBeginCallback($this->article_begin_callback);
        $this->template_obj_creator->doAddTitleFontSize($this->list->getOption('ignore_fontsize') ? false : true);
        $this->template_obj_creator->setArticleEndCallback($this->article_end_callback);
        $this->template_obj_creator->setImageCallback($this->image_callback);
        $this->template_obj_creator->setRelatedCallback($this->related_posts_callback);
        $this->template_obj_creator->setContentCallback($this->content_callback);
    }

    /**
     * Will render all articles in the arlima list using jQuery templates. The template to be
     * used is an option in the article list object (Arlima_List). If no template exists in declared
     * template paths we will fall back on default templates (plugins/arlima/template/[name].tmpl)
     *
     * @param bool $output[optional=true]
     * @return string
     */
    function renderList($output = true)
    {

        $article_counter = 0;
        $content = '';

        list($this->default_template_obj,
            $this->default_template_name) = $this->loadTemplate($this->list->getOption('template'));

        if( empty($this->default_template_obj) ) {
            $message = 'You are using a default template for the list "'.$this->list->getTitle().'" that could not be found';
            if( $output ) {
                echo $message;
            } else {
                return $message;
            }
        }

        // Setup tmpl object creator
        $this->setupObjectCreator();
        $articles = $this->getArticlesToRender();

        do_action('arlima_rendering_init');

        foreach ($articles as $article_data) {
            list($article_counter, $article_content) = $this->outputArticle(
                $article_data,
                $article_counter
            );

            if ( $output ) {
                echo $article_content;
            } else {
                $content .= $article_content;
            }

            if ( $article_counter == $this->getLimit() ) {
                break;
            }
        }

        // unset global post data
        $GLOBALS['post'] = null;
        wp_reset_query();

        return $content;
    }

    /**
     * @param array|stdClass $article_data
     * @param int $article_counter
     * @return array
     */
    protected function outputArticle($article_data, $article_counter)
    {
        // File include
        if ( $this->isFileIncludeArticle($article_data) ) {
            // We're done, go on pls!
            return array($article_counter + 1, $this->includeArticleFile($article_data));
        }

        // Scheduled article
        if ( !empty($article_data['options']['scheduled']) ) {
            if ( !$this->isInScheduledInterval($article_data['options']['scheduledInterval']) ) {
                return array($article_counter, ''); // don't show this scheduled article right now
            }
        }

        // Setup
        list($post, $article, $is_post, $is_empty) = $this->setup($article_data);

        // Future article
        if ( !empty($article_data['published']) && $article_data['published'] > $this->now ) {
            return array(
                    $article_counter,
                    call_user_func(
                        $this->future_post_callback,
                        $post,
                        $article,
                        $this->list,
                        $article_counter
                    )
                );
        }

        list($mustache_template, $template_name) = $this->loadTemplate($article);

        $template_data = $this->template_obj_creator->create(
                            $article,
                            $is_empty,
                            $post,
                            $article_counter,
                            true,
                            $template_name
                        );

        // Add class that makes it possible to target the first article in the list
        if( $article_counter == 0 ) {
            $template_data['class'] .= ' first-in-list';
        }
    
        $has_child_articles = !empty($article['children']) && is_array($article['children']);

        // load sub articles if there's any
        if ( $has_child_articles ) {
            $template_data['child_articles'] = $this->renderChildArticles($article['children']);
        }

        // output the article
        if( $is_empty && !$has_child_articles ) {
            $content = ''; // empty article, don't render!
        } else {
            $content = $this->generateTemplateOutput($mustache_template, $template_data);
        }

        return array($article_counter + 1, $content);
    }

    /**
     * @param Mustache_Template $mustache_template
     * @param array $template_data
     * @return string
     */
    private function generateTemplateOutput($mustache_template, $template_data)
    {
        return $mustache_template->render($template_data);
    }

    /**
     * Will try to parse a schedule-interval-formatted string and determine
     * if we're currently in this time interval
     * @example
     *  isInScheduledInterval('*:*');
     *  isInScheduledInterval('Mon,Tue,Fri:*');
     *  isInScheduledInterval('*:10-12');
     *  isInScheduledInterval('Thu:12,15,18');
     *
     * @param string $schedule_interval
     * @return bool
     */
    private function isInScheduledInterval($schedule_interval)
    {
        $interval_part = explode(':', $schedule_interval);
        if ( count($interval_part) == 2 ) {

            // Check day
            if ( trim($interval_part[0]) != '*' ) {

                $current_day = strtolower(date('D', $this->now + (get_option('gmt_offset') * 3600)));
                $days = array();
                foreach (explode(',', $interval_part[0]) as $day) {
                    $days[] = strtolower(substr(trim($day), 0, 3));
                }

                if ( !in_array($current_day, $days) ) {
                    return false; // don't show article today
                }

            }

            // Check hour
            if ( trim($interval_part[1]) != '*' ) {

                $current_hour = (int)date('H', $this->now + (get_option('gmt_offset') * 3600));
                $from_to = explode('-', $interval_part[1]);
                if ( count($from_to) == 2 ) {
                    $from = (int)trim($from_to[0]);
                    $to = (int)trim($from_to[1]);
                    if ( $current_hour < $from || $current_hour > $to ) {
                        return false; // don't show article this hour
                    }
                } else {
                    $hours = array();
                    foreach (explode(',', $interval_part[1]) as $hour) {
                        $hours[] = (int)trim($hour);
                    }

                    if ( !in_array($current_hour, $hours) ) {
                        return false; // don't show article this hour
                    }
                }
            }
        }

        return true;
    }

    /**
     * @param array $articles
     * @return string
     */
    private function renderChildArticles(array $articles)
    {
        $child_articles = '';
        $count = 0;
        $has_open_child_wrapper = false;
        $group_children_vk_style = apply_filters('arlima_group_children', true) === true;
        $num_children = count($articles);
        $has_even_children = $num_children % 2 === 0;
        $is_child_split = $num_children > 1;
        $image_size = !$is_child_split ? $this->img_size_name_sub_article_full : $this->img_size_name_sub_article;

        // if $group_children_vk_style is false will the variable $has_open_child_wrapper always be false
        // and then no grouping will be applied

        // Configure object creator for child articles
        $this->template_obj_creator->setImgSize($image_size);
        $this->template_obj_creator->setIsChild(true);

        foreach ($articles as $article_data) {

            $this->template_obj_creator->setIsChildSplit(false);
            $first_or_last_class = '';

            if(
                $group_children_vk_style && (
                    ($num_children == 4 && ($count == 1 || $count == 2)) ||
                    ($num_children == 6 && ($count != 0 && $count != 3)) ||
                    ($num_children > 1 && $num_children != 4 && $num_children != 6 && ($count != 0 || $has_even_children) )
                )
            ) {
                $this->template_obj_creator->setIsChildSplit( true );
                $first_or_last_class = (($count==1 && $num_children > 2) || ($count==0 && $num_children==2) || $count==3 || ($count==4 && $num_children ==6)? ' first':' last');
                if( $first_or_last_class == ' first' ) {
                    $child_articles .= '<div class="arlima child-wrapper">';
                    $has_open_child_wrapper = true;
                }
            }

            // File include
            if( $this->isFileIncludeArticle($article_data) ) {
                $count++;
                $child_articles .= '<div class="arlima-file-include teaser '.$first_or_last_class.
                                    ( $this->template_obj_creator->getIsChildSplit() ? ' teaser-split':'').
                                    '">'.$this->includeArticleFile($article_data).'</div>';
                continue;
            }

            list($post, $article, $is_post, $is_empty) = $this->setup($article_data);

            if ( is_object($post) && $post->post_status == 'future' ) {
                if( $group_children_vk_style && $has_open_child_wrapper  && $first_or_last_class == ' last' ) {
                    $child_articles .= '</div>';
                    $has_open_child_wrapper = false;
                }
                continue;
            }

            list($mustache_template, $template_name) = $this->loadTemplate($article);

            $template_data = $this->template_obj_creator->create(
                                $article,
                                $is_empty,
                                $post,
                                -1,
                                false,
                                $template_name
                            );

            if( $first_or_last_class ) {
                $template_data['class'] .= $first_or_last_class;
            }

            $child_articles .= $this->generateTemplateOutput($mustache_template, $template_data);

            $count++;
            if( $has_open_child_wrapper && $first_or_last_class == ' last') {
                $child_articles .= '</div>';
                $has_open_child_wrapper = false;
            }
        }

        if( $has_open_child_wrapper )
            $child_articles .= '</div>';

        // Reset configuration for child articles
        $this->template_obj_creator->setIsChild(false);
        $this->template_obj_creator->setIsChildSplit(false);
        $this->template_obj_creator->setImgSize($this->img_size_name);

        return $child_articles;
    }

    /**
     * Load template that should be used for given article.
     * @param array|string $article_or_tmpl_name
     * @return array array(Mustache_Template, name)
     */
    protected function loadTemplate($article_or_tmpl_name) {
        $is_article_input = is_array($article_or_tmpl_name);
        if( $is_article_input && empty($article_or_tmpl_name['options']['template']) ) {
            return array($this->default_template_obj, $this->default_template_name);
        }
        elseif( $is_article_input ) {
            $template_name = $article_or_tmpl_name['options']['template'];
        } else {
            $template_name = $article_or_tmpl_name;
        }

        if ( isset(self::$preloaded_templates[$template_name]) ) {
            if( self::$preloaded_templates[$template_name] === '' ) {
                // Don't search for template more than once, we have searched for this template
                // but it was not found == return default object
                return array($this->default_template_obj, $this->default_template_name);
            }
            return array(self::$preloaded_templates[$template_name], $template_name);
        }

        $template_paths = $this->template_resolver->getPaths();
        foreach ($template_paths as $template_path) {
            $template_file = $template_path . DIRECTORY_SEPARATOR . $template_name . Arlima_TemplatePathResolver::TMPL_EXT;
            if ( file_exists($template_file) ) {
                self::$preloaded_templates[$template_name] = $this->fileToMustacheTemplate($template_file);
                return array(self::$preloaded_templates[$template_name], $template_name);
            }
        }

        // Template file not found, return default
        self::$preloaded_templates[$template_name] = '';
        return array($this->default_template_obj, $this->default_template_name);
    }

    /**
     * Takes a file and turns it into a mustache template object
     * @see ListTemplateRenderer::loadTemplate()
     *
     * @param string $template_file
     * @return Mustache_Template
     */
    private function fileToMustacheTemplate($template_file)
    {
        // Load template content
        $template_content = file_get_contents($template_file);

        // Merge with includes
        preg_match_all('(\{\{include .*[^ ]\}\})', $template_content, $sub_parts);
        while ( !empty($sub_parts) && !empty($sub_parts[0]) ) {

            $template_path = dirname($template_file) . '/';
            foreach ($sub_parts[0] as $tpl_part) {
                $path = str_replace(array('{{include ', '}}'), '', $tpl_part);
                $included_tmpl = $template_path . $path;
                if ( file_exists($included_tmpl) ) {
                    $template_content = str_replace($tpl_part, file_get_contents($included_tmpl), $template_content);
                } else {
                    $template_content = str_replace(
                        $tpl_part,
                        '{{! ERROR: ' . $included_tmpl . ' does not exist}}',
                        $template_content
                    );
                }
            }
            preg_match_all('(\{\{include [0-9a-z\/A-Z\-\_\.]*\}\})', $template_content, $sub_parts);
        }

        // Remove image support declarations
        $template_content = preg_replace('(\{\{image-support .*\}\})', '', $template_content);

        return $this->template_engine->loadTemplate($template_content);
    }

    /**
     * @param $article_data
     * @return string
     */
    protected function includeArticleFile($article_data)
    {
        $file_include = new Arlima_FileInclude();
        $args = array();
        if (!empty($article_data['options']['fileArgs'])) {
            parse_str($article_data['options']['fileArgs'], $args);
        }

        return $file_include->includeFile($article_data['options']['fileInclude'], $args, $this, $article_data);
    }

    /**
     * @param $article_data
     * @return bool
     */
    protected function isFileIncludeArticle($article_data)
    {
        return !empty($article_data['options']) && !empty($article_data['options']['fileInclude']);
    }
}