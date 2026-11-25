<?php
defined( 'ABSPATH' ) || exit;

/**
 * Settings page under Settings -> LLMs.txt (FR-18..FR-20, FR-22).
 * Tabs for domains, content and the wizard come later; v0.2 has General and Status.
 */
class RT_LLMS_Admin {

	const SLUG  = 'rt-llms-txt';
	const NONCE = 'rt_llms_admin';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_rt_llms_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_rt_llms_regenerate', array( __CLASS__, 'handle_regenerate' ) );
	}

	public static function menu() {
		add_options_page( 'RT LLMs.txt', 'LLMs.txt', 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	private static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'options-general.php' ) );
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'rt-llms-txt' ), 403 );
		}
		check_admin_referer( self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$in = isset( $_POST['rt_llms'] ) && is_array( $_POST['rt_llms'] ) ? wp_unslash( $_POST['rt_llms'] ) : array();
		// phpcs:enable
		RT_LLMS_Settings::save( $in );
		RT_LLMS_Scheduler::schedule_next();
		RT_LLMS_Cache::rebuild();

		wp_safe_redirect( self::url( array( 'saved' => 1 ) ) );
		exit;
	}

	public static function handle_regenerate() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'rt-llms-txt' ), 403 );
		}
		check_admin_referer( self::NONCE );
		RT_LLMS_Cache::rebuild();
		wp_safe_redirect( self::url( array( 'regenerated' => 1 ) ) );
		exit;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = RT_LLMS_Settings::get();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display flags only.
		$saved       = isset( $_GET['saved'] );
		$regenerated = isset( $_GET['regenerated'] );
		// phpcs:enable
		?>
		<div class="wrap">
			<h1>RT LLMs.txt</h1>
			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p>Settings saved and files rebuilt.</p></div>
			<?php elseif ( $regenerated ) : ?>
				<div class="notice notice-success is-dismissible"><p>Files rebuilt.</p></div>
			<?php endif; ?>

			<?php self::render_status(); ?>
			<?php self::render_form( $s ); ?>
			<?php self::render_preview( $s ); ?>
		</div>
		<?php
	}

	private static function render_status() {
		$last = (int) get_option( 'rt_llms_last_run', 0 );
		$log  = get_option( 'rt_llms_log', array() );
		$log  = is_array( $log ) ? array_reverse( $log ) : array();
		?>
		<h2>Status</h2>
		<table class="widefat striped" style="max-width:900px">
			<thead><tr><th>File</th><th>Live URL</th><th>Size</th><th>Last built</th></tr></thead>
			<tbody>
			<?php foreach ( array(
				'short' => '/llms.txt',
				'full'  => '/llms-full.txt',
			) as $key => $path ) :
				$row = RT_LLMS_Cache::get( $key );
				?>
				<tr>
					<td><?php echo esc_html( $path ); ?></td>
					<td><a href="<?php echo esc_url( home_url( $path ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( home_url( $path ) ); ?></a></td>
					<td><?php echo $row ? esc_html( size_format( strlen( $row['text'] ) ) ) : '&mdash;'; ?></td>
					<td><?php echo $row ? esc_html( wp_date( 'Y-m-d H:i', (int) $row['time'] ) ) : 'not built yet'; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p>
			Last nightly run: <strong><?php echo $last ? esc_html( wp_date( 'Y-m-d H:i', $last ) ) : 'never'; ?></strong>.
			<?php
			$next = wp_next_scheduled( RT_LLMS_Scheduler::HOOK );
			if ( $next ) {
				echo ' Next scheduled: <strong>' . esc_html( wp_date( 'Y-m-d H:i', $next ) ) . '</strong>.';
			}
			?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="rt_llms_regenerate">
			<?php wp_nonce_field( self::NONCE ); ?>
			<?php submit_button( 'Regenerate now', 'secondary', 'submit', false ); ?>
		</form>

		<?php if ( $log ) : ?>
			<h3>Recent nightly runs</h3>
			<table class="widefat striped" style="max-width:900px">
				<thead><tr><th>When</th><th>Added</th><th>Removed</th><th>Changed</th><th>Pages fetched</th></tr></thead>
				<tbody>
				<?php foreach ( array_slice( $log, 0, 10 ) as $r ) : ?>
					<tr>
						<td><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) $r['time'] ) ); ?></td>
						<td><?php echo (int) count( $r['added'] ); ?></td>
						<td><?php echo (int) count( $r['removed'] ); ?></td>
						<td><?php echo (int) count( $r['changed'] ); ?></td>
						<td><?php echo (int) $r['fetched']; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	private static function render_form( array $s ) {
		$facts   = array_values( (array) $s['facts'] );
		$blank = 3;
		for ( $i = 0; $i < $blank; $i++ ) {
			$facts[] = array( 'label' => '', 'value' => '' );
		}
		?>
		<h2>Settings</h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="rt_llms_save">
			<?php wp_nonce_field( self::NONCE ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="rt_llms_title">Title (H1)</label></th>
					<td><input type="text" class="regular-text" id="rt_llms_title" name="rt_llms[title]" value="<?php echo esc_attr( $s['title'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="rt_llms_summary">Summary</label></th>
					<td>
						<textarea class="large-text" rows="4" id="rt_llms_summary" name="rt_llms[summary]"><?php echo esc_textarea( $s['summary'] ); ?></textarea>
						<p class="description">One short paragraph: who the site is and what it offers. Shown as the blockquote at the top of both files.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Key facts</th>
					<td>
						<?php foreach ( $facts as $i => $f ) : ?>
							<p>
								<input type="text" name="rt_llms[facts][<?php echo (int) $i; ?>][label]" value="<?php echo esc_attr( $f['label'] ); ?>" placeholder="Label (e.g. Phone)" style="width:200px">
								<input type="text" name="rt_llms[facts][<?php echo (int) $i; ?>][value]" value="<?php echo esc_attr( $f['value'] ); ?>" placeholder="Value" class="regular-text">
							</p>
						<?php endforeach; ?>
						<p class="description">Address, phone, email, recognition, voucher eligibility, formats, and so on. Only rows with both fields are published. Save to get more empty rows.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Post types to list</th>
					<td>
						<?php foreach ( RT_LLMS_Settings::discover_post_types() as $type ) :
							$obj = get_post_type_object( $type );
							if ( ! $obj ) {
								continue;
							}
							$count = wp_count_posts( $type );
							?>
							<label style="display:block">
								<input type="checkbox" name="rt_llms[post_types][]" value="<?php echo esc_attr( $type ); ?>" <?php checked( in_array( $type, $s['post_types'], true ) ); ?>>
								<?php echo esc_html( $obj->labels->name ); ?>
								<code><?php echo esc_html( $type ); ?></code>
								<span class="description">(<?php echo (int) $count->publish; ?> published)</span>
							</label>
						<?php endforeach; ?>
						<p class="description">Untick utility pages such as thank-you pages or templates. Entries are grouped by these types for now.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rt_llms_hour">Nightly run hour</label></th>
					<td>
						<input type="number" min="0" max="23" id="rt_llms_hour" name="rt_llms[run_hour]" value="<?php echo (int) $s['run_hour']; ?>" class="small-text">
						<span class="description">In the site timezone (<?php echo esc_html( wp_timezone_string() ); ?>).</span>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rt_llms_age">Cache lifetime (seconds)</label></th>
					<td>
						<input type="number" min="60" max="86400" id="rt_llms_age" name="rt_llms[max_age]" value="<?php echo (int) $s['max_age']; ?>" class="small-text">
						<p class="description">Sent as Cache-Control max-age. A CDN in front of the site may keep its own copy; the plugin does not purge it.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Description length (characters)</th>
					<td>
						<label>llms.txt <input type="number" min="40" max="1000" name="rt_llms[len_short]" value="<?php echo (int) $s['len_short']; ?>" class="small-text"></label>
						<label style="margin-left:1em">llms-full.txt <input type="number" min="40" max="1000" name="rt_llms[len_full]" value="<?php echo (int) $s['len_full']; ?>" class="small-text"></label>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Save and rebuild' ); ?>
		</form>
		<?php
	}

	/** FR-20: show what the files would contain with the saved settings. */
	private static function render_preview( array $s ) {
		$builder = new RT_LLMS_Builder( $s );
		?>
		<h2>Preview</h2>
		<p class="description">Built from the saved settings, not from the cache.</p>
		<?php foreach ( array(
			'short' => 'llms.txt',
			'full'  => 'llms-full.txt',
		) as $key => $name ) : ?>
			<details style="margin-bottom:1em">
				<summary><strong><?php echo esc_html( $name ); ?></strong></summary>
				<textarea readonly class="large-text code" rows="18" dir="auto"><?php echo esc_textarea( $builder->build( $key ) ); ?></textarea>
			</details>
		<?php endforeach; ?>
		<?php
	}
}
