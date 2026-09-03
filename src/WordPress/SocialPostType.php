<?php
/**
 * Native Social Posts content type and filtering taxonomies.
 *
 * @package AtomicWPSocialSync
 */

namespace AtomicWPSocialSync\WordPress;

use AtomicWPSocialSync\Support\PluginSettings;

final class SocialPostType {
	public const POST_TYPE           = 'atomic_social_post';
	public const PROVIDER_TAXONOMY   = 'atomic_social_source';
	public const CONNECTION_TAXONOMY = 'atomic_social_account';

	public function __construct( private readonly PluginSettings $settings ) {}

	public function register(): void {
		$single_pages_enabled = (bool) $this->settings->get( 'enable_single_pages', false );
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Social Posts', 'atomic-wp-social-sync' ),
					'singular_name' => __( 'Social Post', 'atomic-wp-social-sync' ),
					'add_new_item'  => __( 'Add Social Post', 'atomic-wp-social-sync' ),
					'edit_item'     => __( 'Edit Social Post', 'atomic-wp-social-sync' ),
					'menu_name'     => __( 'Social Posts', 'atomic-wp-social-sync' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_rest'        => true,
				'publicly_queryable'  => $single_pages_enabled,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'rewrite'             => $single_pages_enabled ? array( 'slug' => 'social-post' ) : false,
				'menu_icon'           => 'dashicons-share',
				'supports'            => array( 'title', 'editor', 'excerpt', 'thumbnail', 'revisions' ),
			)
		);

		$this->registerTaxonomy( self::PROVIDER_TAXONOMY, __( 'Social Sources', 'atomic-wp-social-sync' ) );
		$this->registerTaxonomy( self::CONNECTION_TAXONOMY, __( 'Social Accounts', 'atomic-wp-social-sync' ) );
	}

	private function registerTaxonomy( string $taxonomy, string $label ): void {
		register_taxonomy(
			$taxonomy,
			self::POST_TYPE,
			array(
				'labels'            => array( 'name' => $label ),
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'hierarchical'      => false,
				'rewrite'           => false,
			)
		);
	}
}
