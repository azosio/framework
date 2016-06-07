<?php

namespace Themosis\Taxonomy;

use Illuminate\View\Factory;
use Themosis\Foundation\DataContainer;
use Themosis\Field\Wrapper;
use Themosis\Hook\IHook;
use Themosis\Validation\ValidationBuilder;

class TaxonomyBuilder extends Wrapper
{
    /**
     * Store the taxonomy data.
     *
     * @var DataContainer
     */
    protected $datas;

    /**
     * @var IHook
     */
    protected $action;

    /**
     * @var ValidationBuilder
     */
    protected $validator;

    /**
     * @var Factory
     */
    protected $view;

    /**
     * The taxonomy custom fields.
     * 
     * @var array
     */
    protected $fields = [];

    /**
     * Build a TaxonomyBuilder instance.
     *
     * @param DataContainer                          $datas     The taxonomy properties.
     * @param \Themosis\Hook\IHook                   $action
     * @param \Themosis\Validation\ValidationBuilder $validator
     * @param \Illuminate\View\Factory               $view
     */
    public function __construct(DataContainer $datas, IHook $action, ValidationBuilder $validator, Factory $view)
    {
        $this->datas = $datas;
        $this->action = $action;
        $this->validator = $validator;
        $this->view = $view;
    }

    /**
     * @param string       $name     The taxonomy slug name.
     * @param string|array $postType The taxonomy object type slug: 'post', 'page', ...
     * @param string       $plural   The taxonomy plural display name.
     * @param string       $singular The taxonomy singular display name.
     *
     * @throws TaxonomyException
     *
     * @return \Themosis\Taxonomy\TaxonomyBuilder
     */
    public function make($name, $postType, $plural, $singular)
    {
        $params = compact('name', 'postType', 'plural', 'singular');

        foreach ($params as $key => $param) {
            if ('postType' !== $key && !is_string($param)) {
                throw new TaxonomyException('Invalid taxonomy parameter "'.$key.'"');
            }
        }

        // Store properties.
        $this->datas['name'] = $name;
        $this->datas['postType'] = (array) $postType;
        $this->datas['args'] = $this->setDefaultArguments($plural, $singular);

        return $this;
    }

    /**
     * Set the custom taxonomy. A user can also override the
     * arguments by passing an array of taxonomy arguments.
     *
     * @link http://codex.wordpress.org/Function_Reference/register_taxonomy
     *
     * @param array $params Taxonomy arguments to override defaults.
     *
     * @return \Themosis\Taxonomy\TaxonomyBuilder
     */
    public function set(array $params = [])
    {
        // Override custom taxonomy arguments if given.
        $this->datas['args'] = array_merge($this->datas['args'], $params);

        // Trigger the 'init' event in order to register the custom taxonomy.
        // Check if we are not already called by a method attached to the `init` hook.
        $current = current_filter();

        if ('init' === $current) {
            // If inside an `init` action, simply call the register method.
            $this->register();
        } else {
            // Out of an `init` action, call the hook.
            $this->action->add('init', [$this, 'register']);
        }

        return $this;
    }

    /**
     * Triggered by the 'init' action/event.
     * Register the custom taxonomy.
     */
    public function register()
    {
        register_taxonomy($this->datas['name'], $this->datas['postType'], $this->datas['args']);
    }

    /**
     * Link the taxonomy to its custom post type. Allow the taxonomy
     * to be found in 'parse_query' or 'pre_get_posts' filters.
     *
     * @link http://codex.wordpress.org/Function_Reference/register_taxonomy_for_object_type
     *
     * @return \Themosis\Taxonomy\TaxonomyBuilder
     */
    public function bind()
    {
        foreach ($this->datas['postType'] as $objectType) {
            register_taxonomy_for_object_type($this->datas['name'], $objectType);
        }

        return $this;
    }

    /**
     * Set the taxonomy default arguments.
     *
     * @param string $plural   The plural display name.
     * @param string $singular The singular display name.
     *
     * @return array
     */
    protected function setDefaultArguments($plural, $singular)
    {
        $labels = [
            'name' => _x($plural, THEMOSIS_FRAMEWORK_TEXTDOMAIN),
            'singular_name' => _x($singular, THEMOSIS_FRAMEWORK_TEXTDOMAIN),
            'search_items' => __('Search '.$plural, THEMOSIS_FRAMEWORK_TEXTDOMAIN),
            'all_items' => __('All '.$plural, THEMOSIS_FRAMEWORK_TEXTDOMAIN),
            'parent_item' => __('Parent '.$singular, THEMOSIS_FRAMEWORK_TEXTDOMAIN),
            'parent_item_colon' => __('Parent '.$singular.': ', THEMOSIS_FRAMEWORK_TEXTDOMAIN),
            'edit_item' => __('Edit '.$singular, THEMOSIS_FRAMEWORK_TEXTDOMAIN),
            'update_item' => __('Update '.$singular, THEMOSIS_FRAMEWORK_TEXTDOMAIN),
            'add_new_item' => __('Add New '.$singular, THEMOSIS_FRAMEWORK_TEXTDOMAIN),
            'new_item_name' => __('New '.$singular.' Name', THEMOSIS_FRAMEWORK_TEXTDOMAIN),
            'menu_name' => __($plural, THEMOSIS_FRAMEWORK_TEXTDOMAIN),
        ];

        $defaults = [
            'label' => __($plural, THEMOSIS_FRAMEWORK_TEXTDOMAIN),
            'labels' => $labels,
            'public' => true,
            'query_var' => true,
        ];

        return $defaults;
    }

    /**
     * Return a defined taxonomy property.
     *
     * @param null $property
     *
     * @return array
     * 
     * @throws TaxonomyException
     */
    public function get($property = null)
    {
        $args = [
            'slug' => $this->datas['name'],
            'post_type' => $this->datas['postType'],
        ];

        $properties = array_merge($args, $this->datas['args']);

        // If no property asked, return all defined properties.
        if (is_null($property) || empty($property)) {
            return $properties;
        }

        // If property exists, return it.
        if (isset($properties[$property])) {
            return $properties[$property];
        }

        throw new TaxonomyException("Property '{$property}' does not exist on the '{$properties['label']}' taxonomy.");
    }

    /**
     * Register/display taxonomy custom fields.
     * Can be called without the need to create a custom taxonomy previously (pass taxonomy name as second
     * parameter to the method).
     *
     * @param array  $fields   A list of custom fields to use.
     * @param string $taxonomy The taxonomy name used to attach the fields to.
     *
     * @return \Themosis\Taxonomy\TaxonomyBuilder
     */
    public function addFields(array $fields, $taxonomy = '')
    {
        // Check taxonomy.
        if (empty($taxonomy) && isset($this->datas['name'])) {
            $taxonomy = $this->datas['name'];
        }

        // Second check if $taxonomy has been omitted.
        if (empty($taxonomy)) {
            return $this;
        }

        // Save fields with the instance.
        $this->fields = $fields;

        /*
         * Let's initialize term meta...
         */
        $current = current_filter();

        if ('init' === $current) {
            // If inside an `init` action, simply call the method.
            $this->registerFields();
        } else {
            // Out of an `init` action, call the hook.
            $this->action->add('init', [$this, 'registerFields']);
        }

        /*
         * Let's add the fields...
         */
        $this->action->add($taxonomy.'_add_form_fields', [$this, 'displayAddFields']);
        $this->action->add($taxonomy.'_edit_form_fields', [$this, 'displayEditFields']);

        /*
         * Let's handle the save...
         */
        $this->action->add('create_'.$taxonomy, [$this, 'save']);
        $this->action->add('edit_'.$taxonomy, [$this, 'save']);

        return $this;
    }

    /**
     * Register the term meta.
     */
    public function registerFields()
    {
        foreach ($this->fields as $field) {
            register_meta('term', $field['name'], [$this, 'sanitizeField']);
        }
    }

    /**
     * Used to run the sanitize callbacks.
     *
     * @param mixed  $value
     * @param string $key
     * @param string $type
     *
     * @return mixed
     */
    public function sanitizeField($value, $key, $type)
    {
        $rules = $this->datas['rules.sanitize'];

        $rule = isset($rules[$key]) ? $rules[$key] : ['html'];

        return $this->validator->single($value, $rule);
    }

    public function displayAddFields()
    {
        echo($this->view->make('_themosisCoreTaxonomyAdd', ['fields' => $this->fields])->render());
    }

    public function displayEditFields()
    {
        echo($this->view->make('_themosisCoreTaxonomyEdit', ['fields' => $this->fields])->render());
    }

    public function save()
    {
    }

    /**
     * Sanitize custom fields values by using passed rules.
     *
     * @param array $rules Sanitize rules.
     *
     * @return \Themosis\Taxonomy\TaxonomyBuilder
     */
    public function sanitize(array $rules = [])
    {
        $this->datas['rules.sanitize'] = $rules;

        return $this;
    }
}
