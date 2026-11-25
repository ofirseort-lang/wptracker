<?php
defined( 'ABSPATH' ) || exit;

/**
 * Per-post controls (FR-21): exclude from the files, and mark as featured in
 * the curated llms.txt. The domain selector arrives with the taxonomy.
 */
class RT_LLMS_Metabox {

	const EXCLUDE  = '_rt_llms_exclude';
	const FEATURED = '_rt_llms_featured';
	const NONCE    = 'rt_llms_metabox';

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register' ) );
		add_action( 'save_post', array( __CLASS__, 'save' ), 10, 2 );
	}

	public static function register() {
		foreach ( RT_LLMS_Settings::get()['post_types'] as $type ) {
			add_meta_box( 'rt_llms_box', 'LLMs.txt', array( __CLASS__, 'render' ), $type, 'side', 'low' );
		}
	}

	public static function render( $post ) {
		wp_nonce_field( self::NONCE, self::NONCE );
		?>
		<p>
			<label>
				<input type="checkbox" name="rt_llms_exclude" value="1" <?php checked( (bool) get_post_meta( $post->ID, self::EXCLUDE, true ) ); ?>>
				Exclude from llms files
			</label>
		</p>
		<p>
			<label>
				<input type="checkbox" name="rt_llms_featured" value="1" <?php checked( (bool) get_post_meta( $post->ID, self::FEATURED, true ) ); ?>>
				Featured in llms.txt
			</label>
		</p>
		<?php
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$was_excluded = (bool) get_post_meta( $post_id, self::EXCLUDE, true );
		$exclude      = ! empty( $_POST['rt_llms_exclude'] );
		self::set( $post_id, self::EXCLUDE, $exclude );
		self::set( $post_id, self::FEATURED, ! empty( $_POST['rt_llms_featured'] ) );

		// FR-24a: excluding a published page removes it from the files right away.
		if ( $exclude && ! $was_excluded && 'publish' === $post->post_status ) {
			RT_LLMS_Scheduler::mark_dirty();
		}
	}

	private static function set( $post_id, $key, $on ) {
		if ( $on ) {
			update_post_meta( $post_id, $key, 1 );
		} else {
			delete_post_meta( $post_id, $key );
		}
	}
}
