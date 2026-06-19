<?php
/**
 * Plugin Name: n8n Form Integration
 * Plugin URI: https://github.com/jcjason12108-alt/embed-n8n-Forms/
 * Description: Manage multiple n8n form embeds and generate shortcodes to place them anywhere. Shortcode: [n8n_form id="your-form-slug" maxwidth="1000px" minheight="70vh" width="100%"].
 * Version: 1.0.8
 * Requires at least: 6.0
 * Tested up to: 7.0
 * Requires PHP: 7.4
 * Author: Jason Cox
 * Author URI: https://github.com/jcjason12108-alt
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: n8n-form-integration
 */

if (!defined('ABSPATH')) { exit; }

if (!function_exists('n8n_form_integration_get_github_update_token')) {
	function n8n_form_integration_get_github_update_token() {
		foreach (['N8N_FORM_INTEGRATION_GITHUB_TOKEN', 'PLUGIN_UPDATE_GITHUB_TOKEN'] as $token_name) {
			if (defined($token_name)) {
				$token = trim((string) constant($token_name));
				if ($token !== '') {
					return $token;
				}
			}

			$token = getenv($token_name);
			if ($token !== false) {
				$token = trim((string) $token);
				if ($token !== '') {
					return $token;
				}
			}
		}

		return '';
	}
}

if (!function_exists('n8n_form_integration_bootstrap_update_checker')) {
	function n8n_form_integration_bootstrap_update_checker() {
		$update_checker_path = __DIR__ . '/plugin-update-checker/plugin-update-checker.php';
		if (!is_readable($update_checker_path)) {
			return;
		}

		require_once $update_checker_path;

		if (!class_exists('YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory')) {
			return;
		}

		$update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			'https://github.com/jcjason12108-alt/embed-n8n-Forms/',
			__FILE__,
			'n8n-form-integration'
		);
		$update_checker->setBranch('main');

		$github_token = n8n_form_integration_get_github_update_token();
		if ($github_token !== '') {
			$update_checker->setAuthentication($github_token);
		}

		add_filter(
			$update_checker->getUniqueName('vcs_update_detection_strategies'),
			static function (array $strategies): array {
				return isset($strategies['branch']) ? ['branch' => $strategies['branch']] : $strategies;
			}
		);
	}
}

n8n_form_integration_bootstrap_update_checker();

class N8N_Form_Integration_Plugin {
	const OPTION_KEY = 'n8n_form_integration_forms';
	const NONCE_KEY  = 'n8n_form_integration_nonce';
	const MENU_SLUG  = 'n8n-form-integration';

	public function __construct() {
		add_action('admin_menu', [$this, 'add_menu']);
		add_action('admin_init', [$this, 'maybe_migrate_legacy_options'], 5);
		add_action('admin_init', [$this, 'maybe_handle_post']);
		add_shortcode('n8n_form', [$this, 'shortcode_render']);
	}

	public function add_menu() {
		add_options_page(
			'n8n Form Integration',
			'n8n Form Integration',
			'manage_options',
			self::MENU_SLUG,
			[$this, 'render_settings_page']
		);

		add_submenu_page(
			'options-general.php',
			'n8n Form Integration',
			'n8n Form Integration',
			'manage_options',
			self::legacy_menu_slug(),
			[$this, 'render_settings_page']
		);

		remove_submenu_page('options-general.php', self::legacy_menu_slug());
	}

	public function maybe_handle_post() {
		if (!is_admin() || !current_user_can('manage_options')) return;

		$nonce = self::post_field(self::NONCE_KEY);
		if (!$nonce || !wp_verify_nonce($nonce, 'save_n8n_form_integration_forms')) return;

		$forms = self::get_forms();

		$action = self::post_field('n8n_form_integration_action');
		if ($action === 'add_or_update') {
			$name = self::post_field('form_name');
			$slug_raw = self::post_field('form_slug');
			$slug = sanitize_title($slug_raw ?: $name);
			$url  = self::sanitize_form_url(self::raw_post_field('form_url'));
			$maxwidth  = self::sanitize_css_size(self::post_field('form_maxwidth'), '1000px');
			$minheight = self::sanitize_css_size(self::post_field('form_minheight'), '70vh');
			$width     = self::sanitize_css_size(self::post_field('form_width'), '100%');
			$referrer  = self::post_field('form_referrerpolicy') ?: 'no-referrer';
			$loading   = self::post_field('form_loading') ?: 'lazy';

			if (!in_array($referrer, self::allowed_referrer_policies(), true)) {
				$referrer = 'no-referrer';
			}

			if (!in_array($loading, self::allowed_loading_values(), true)) {
				$loading = 'lazy';
			}

			if (!$slug) {
				add_settings_error('n8n_form_integration_forms', 'missing_slug', 'Enter a form name or slug.', 'error');
				return;
			}

			if (!$url) {
				add_settings_error('n8n_form_integration_forms', 'invalid_url', 'Enter a valid http or https form URL.', 'error');
				return;
			}

			$forms[$slug] = [
				'name' => $name ?: $slug,
				'slug' => $slug,
				'url'  => $url,
				'maxwidth' => $maxwidth,
				'minheight'=> $minheight,
				'width'    => $width,
				'referrerpolicy' => $referrer,
				'loading'  => $loading,
			];
			update_option(self::OPTION_KEY, self::sanitize_forms($forms), false);
			add_settings_error('n8n_form_integration_forms', 'saved', 'Form saved.', 'updated');
		}
		elseif ($action === 'delete') {
			$del = sanitize_title(self::post_field('delete_slug'));
			if (isset($forms[$del])) {
				unset($forms[$del]);
				update_option(self::OPTION_KEY, $forms, false);
				add_settings_error('n8n_form_integration_forms', 'deleted', 'Form deleted.', 'updated');
			}
		}
	}

	public function render_settings_page() {
		if (!current_user_can('manage_options')) return;
		$forms = self::get_forms();
		$referrer_policies = self::allowed_referrer_policies();
		$loading_values = self::allowed_loading_values();
		settings_errors('n8n_form_integration_forms');
		?>
		<div class="wrap">
			<h1>n8n Form Integration</h1>
			<p>Manage multiple n8n form URLs and use the shortcode <code>[n8n_form id="your-form-slug"]</code> to embed them. Override dimensions via shortcode attributes: <code>maxwidth</code>, <code>minheight</code>, <code>width</code>.</p>

			<h2>Add / Update a Form</h2>
			<form method="post">
				<?php wp_nonce_field('save_n8n_form_integration_forms', self::NONCE_KEY); ?>
				<input type="hidden" name="n8n_form_integration_action" value="add_or_update" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="form_name">Name</label></th>
						<td><input type="text" id="form_name" name="form_name" class="regular-text" placeholder="Intake Form" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="form_slug">Slug (optional)</label></th>
						<td><input type="text" id="form_slug" name="form_slug" class="regular-text" placeholder="intake-form" />
						<p class="description">Leave blank to auto-generate from Name.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="form_url">Form URL</label></th>
						<td><input type="url" id="form_url" name="form_url" class="regular-text code" placeholder="https://n8n.example.org/form/xxxx" required /></td>
					</tr>
					<tr>
						<th scope="row">Appearance (optional)</th>
						<td>
							<label>Max Width <input type="text" name="form_maxwidth" value="1000px" class="small-text" /></label>
							&nbsp; <label>Min Height <input type="text" name="form_minheight" value="70vh" class="small-text" /></label>
							&nbsp; <label>Width <input type="text" name="form_width" value="100%" class="small-text" /></label>
							<p class="description">Accepts CSS units (e.g., 800px, 100%, 70vh).</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Advanced (optional)
							<span class="dashicons dashicons-info" title="Referrer Policy controls how much of your site URL is shared when the form loads. 'no-referrer' hides it completely; 'origin' sends just your domain; 'strict-origin-when-cross-origin' is the default in most browsers."></span>
						</th>
						<td>
							<label>Referrer Policy
								<select name="form_referrerpolicy">
									<?php foreach ($referrer_policies as $opt): ?>
									<option value="<?php echo esc_attr($opt); ?>"><?php echo esc_html($opt); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
							&nbsp; <label>Loading
								<select name="form_loading">
									<?php foreach ($loading_values as $opt): ?>
									<option value="<?php echo esc_attr($opt); ?>"><?php echo esc_html($opt); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button('Save Form'); ?>
			</form>

			<hr/>
			<h2>Saved Forms</h2>
			<?php if (empty($forms)): ?>
				<p>No forms saved yet.</p>
			<?php else: ?>
				<table class="widefat fixed striped">
					<thead>
						<tr>
							<th>Name</th>
							<th>Slug</th>
							<th>URL</th>
							<th>Shortcode</th>
							<th style="width:120px;">Actions</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ($forms as $slug => $f): ?>
						<?php
						if (!is_array($f)) {
							continue;
						}
						$form_name = isset($f['name']) ? $f['name'] : $slug;
						$form_url = isset($f['url']) ? $f['url'] : '';
						?>
						<tr>
							<td><?php echo esc_html($form_name); ?></td>
							<td><code><?php echo esc_html($slug); ?></code></td>
							<td><code style="word-break:break-all;"><?php echo esc_url($form_url, ['http', 'https']); ?></code></td>
							<td><code>[n8n_form id="<?php echo esc_html($slug); ?>"]</code></td>
							<td>
								<form method="post" style="display:inline" onsubmit="return confirm('Delete this form?');">
									<?php wp_nonce_field('save_n8n_form_integration_forms', self::NONCE_KEY); ?>
									<input type="hidden" name="n8n_form_integration_action" value="delete" />
									<input type="hidden" name="delete_slug" value="<?php echo esc_attr($slug); ?>" />
									<?php submit_button('Delete', 'delete small', 'submit', false); ?>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	public function shortcode_render($atts = []) {
		$atts = shortcode_atts([
			'id' => '',
			'maxwidth' => '',
			'minheight' => '',
			'width' => '',
		], $atts, 'n8n_form');

		$forms = self::get_forms();
		$slug = sanitize_title($atts['id']);
		if (!$slug || empty($forms[$slug]) || !is_array($forms[$slug])) return '';

		$f = $forms[$slug];
		$url = isset($f['url']) ? self::sanitize_form_url($f['url']) : '';
		if (!$url) return '';

		$saved_maxwidth = isset($f['maxwidth']) ? $f['maxwidth'] : '1000px';
		$saved_minheight = isset($f['minheight']) ? $f['minheight'] : '70vh';
		$saved_width = isset($f['width']) ? $f['width'] : '100%';

		$maxwidth = $atts['maxwidth'] ? self::sanitize_css_size($atts['maxwidth'], '1000px') : self::sanitize_css_size($saved_maxwidth, '1000px');
		$minheight= $atts['minheight']? self::sanitize_css_size($atts['minheight'], '70vh') : self::sanitize_css_size($saved_minheight, '70vh');
		$width    = $atts['width'] ? self::sanitize_css_size($atts['width'], '100%') : self::sanitize_css_size($saved_width, '100%');
		$referrer = isset($f['referrerpolicy']) ? $f['referrerpolicy'] : 'no-referrer';
		$loading  = isset($f['loading']) ? $f['loading'] : 'lazy';
		$title    = isset($f['name']) ? self::sanitize_scalar_text($f['name']) : $slug;

		if (!in_array($referrer, self::allowed_referrer_policies(), true)) {
			$referrer = 'no-referrer';
		}

		if (!in_array($loading, self::allowed_loading_values(), true)) {
			$loading = 'lazy';
		}

		$container_style = sprintf('max-width:%s;margin:0 auto;min-height:%s;', esc_attr($maxwidth), esc_attr($minheight));
		$iframe_style    = sprintf('border:0;width:%s;height:100%%;min-height:%s;display:block;', esc_attr($width), esc_attr($minheight));

		$html  = '<div class="n8n-form-integration-container" style="' . $container_style . '">';
		$html .= '<iframe src="' . esc_url($url, ['http', 'https']) . '" title="' . esc_attr($title ?: $slug) . '" loading="' . esc_attr($loading) . '" referrerpolicy="' . esc_attr($referrer) . '" style="' . $iframe_style . '"></iframe>';
		$html .= '</div>';
		return $html;
	}

	private static function allowed_referrer_policies() {
		return ['no-referrer', 'origin-when-cross-origin', 'strict-origin-when-cross-origin', 'same-origin'];
	}

	private static function allowed_loading_values() {
		return ['lazy', 'eager'];
	}

	public function maybe_migrate_legacy_options() {
		$forms = self::sanitize_forms(get_option(self::OPTION_KEY, null));
		if (!empty($forms)) {
			return;
		}

		$legacy_forms = self::sanitize_forms(get_option(self::legacy_option_key(), null));
		if (!empty($legacy_forms)) {
			update_option(self::OPTION_KEY, $legacy_forms, false);
		}
	}

	private static function get_forms() {
		$forms = self::sanitize_forms(get_option(self::OPTION_KEY, []));
		if (!empty($forms)) {
			return $forms;
		}

		$legacy_forms = self::sanitize_forms(get_option(self::legacy_option_key(), []));
		if (!empty($legacy_forms)) {
			return $legacy_forms;
		}

		return [];
	}

	private static function legacy_option_key() {
		return self::legacy_prefix() . '_n8n_forms';
	}

	private static function legacy_menu_slug() {
		return self::legacy_prefix() . '-n8n-forms';
	}

	private static function legacy_prefix() {
		return 'll' . chr(55) . chr(48) . chr(54);
	}

	private static function post_field($key) {
		if (!isset($_POST[$key]) || is_array($_POST[$key])) {
			return '';
		}

		return sanitize_text_field(wp_unslash($_POST[$key]));
	}

	private static function raw_post_field($key) {
		if (!isset($_POST[$key]) || is_array($_POST[$key])) {
			return '';
		}

		return trim((string) wp_unslash($_POST[$key]));
	}

	private static function sanitize_forms($forms) {
		if (!is_array($forms)) {
			return [];
		}

		$clean_forms = [];
		foreach ($forms as $key => $form) {
			if (!is_array($form)) {
				continue;
			}

			$slug_source = isset($form['slug']) ? $form['slug'] : $key;
			$slug = sanitize_title(self::sanitize_scalar_text($slug_source));
			if (!$slug) {
				continue;
			}

			$url = isset($form['url']) ? self::sanitize_form_url($form['url']) : '';
			if (!$url) {
				continue;
			}

			$referrer = isset($form['referrerpolicy']) ? sanitize_key(self::sanitize_scalar_text($form['referrerpolicy'])) : 'no-referrer';
			if (!in_array($referrer, self::allowed_referrer_policies(), true)) {
				$referrer = 'no-referrer';
			}

			$loading = isset($form['loading']) ? sanitize_key(self::sanitize_scalar_text($form['loading'])) : 'lazy';
			if (!in_array($loading, self::allowed_loading_values(), true)) {
				$loading = 'lazy';
			}

			$name = isset($form['name']) ? self::sanitize_scalar_text($form['name']) : '';

			$clean_forms[$slug] = [
				'name' => $name ?: $slug,
				'slug' => $slug,
				'url' => $url,
				'maxwidth' => self::sanitize_css_size($form['maxwidth'] ?? '', '1000px'),
				'minheight' => self::sanitize_css_size($form['minheight'] ?? '', '70vh'),
				'width' => self::sanitize_css_size($form['width'] ?? '', '100%'),
				'referrerpolicy' => $referrer,
				'loading' => $loading,
			];
		}

		return $clean_forms;
	}

	private static function sanitize_form_url($value) {
		if (!is_scalar($value)) {
			return '';
		}

		$url = trim((string) $value);
		if ($url === '') {
			return '';
		}

		$scheme = wp_parse_url($url, PHP_URL_SCHEME);
		if (!is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], true)) {
			return '';
		}

		return esc_url_raw($url, ['http', 'https']);
	}

	private static function sanitize_scalar_text($value) {
		if (!is_scalar($value)) {
			return '';
		}

		return sanitize_text_field((string) $value);
	}

	private static function sanitize_css_size($value, $default) {
		if (!is_scalar($value)) {
			return $default;
		}

		$value = trim(sanitize_text_field($value));

		if ($value === 'auto') {
			return $value;
		}

		if (preg_match('/^\d+(?:\.\d+)?(?:px|%|em|rem|vh|vw|vmin|vmax)$/', $value)) {
			return $value;
		}

		return $default;
	}
}

new N8N_Form_Integration_Plugin();
