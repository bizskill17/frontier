<?php
/**
 * Plugin Name: Frontier
 * Description: Site procurement from BOQ and indent to supplier invoice reconciliation.
 * Version: 1.6.1
 * Requires PHP: 7.4
 */
defined('ABSPATH') || exit;

final class Frontier {
    private const MASTERS = [
        'employee'=>'Employee Master',
        'item'=>'Item Master',
        'category'=>'Item Categories',
        'item_type'=>'Item Type',
        'supplier'=>'Supplier Master',
    ];

    private const GROUPS = [
        'Master' => self::MASTERS + ['site'=>'Site Master'],
        'BOQ' => ['boq_form'=>'Create BOQ', 'boq'=>'BOQ', 'boq_items'=>'BOQ Item Master'],
        'Indent' => ['indent'=>'Indents Form', 'indent_master'=>'Indent Master', 'indent_items'=>'Indents Item Master'],
        'Supplier Quotation' => ['quote_form'=>'Supplier Quotation Form', 'quote'=>'Supplier Quotation Master'],
        'Choose Supplier' => ['choose_form'=>'Choose Supplier Form', 'choose_master'=>'Choose Supplier Master'],
        'Purchase Order' => ['po_form'=>'Create Purchase Order', 'po'=>'Purchase Orders', 'po_items'=>'Purchase Orders Items', 'cancel'=>'Cancel PO Items', 'follow'=>'Follow Up PO Master'],
        'Goods Receipt' => ['grn_form'=>'Goods Receipt Form', 'grn'=>'Goods Receipt Notes', 'grn_items'=>'Goods Receipt Items', 'grn_slip'=>'Upload GRN Slip'],
        'Invoice' => ['invoice'=>'Upload Invoice', 'match'=>'Link PO Items With Invoice'],
    ];

    // Each screen uses the existing procurement record type and workflow.
    private const SCREENS = [
        'boq_form'=>['boq','form'], 'boq'=>['boq','register'], 'boq_items'=>['boq','items'],
        'indent'=>['indent','form'], 'indent_master'=>['indent','register'], 'indent_items'=>['indent','items'],
        'quote_form'=>['quote','form'], 'quote'=>['quote','register'],
        'choose_form'=>['quote','choose'], 'choose_master'=>['quote','selected'],
        'po_form'=>['po','form'], 'po'=>['po','register'], 'po_items'=>['po','items'],
        'grn_form'=>['grn','form'], 'grn'=>['grn','register'], 'grn_items'=>['grn','items'], 'grn_slip'=>['grn','slip'],
    ];

    private static $screen = '';

    private const FIELDS = [
        'employee'=>['name'=>'Name', 'email'=>'Email Id', 'mobile'=>'Mobile', 'department'=>'Department', 'login'=>'User ID', 'password'=>'Password', 'role'=>'Role'],
        'item'=>['name'=>'Name', 'category'=>'Item Category', 'item_type'=>'Item Type'],
        'category'=>['name'=>'Category name'],
        'item_type'=>['name'=>'Type name'],
        'site'=>['ref'=>'Site Number', 'due'=>'Site Date', 'name'=>'Site Name', 'address'=>'Site Address', 'employee'=>'Site Manager', 'telegram'=>'Telegram Group Id'],
        'supplier'=>['ref'=>'Supplier Code', 'name'=>'Supplier Names', 'contact'=>'Contact Number', 'alternate'=>'Alternate Contact Number', 'email'=>'Email Id', 'address'=>'Address', 'city'=>'City', 'state'=>'State', 'pin'=>'PIN', 'mobile'=>'Mobile', 'gst'=>'GST', 'term'=>'Term'],
        'boq'=>['site'=>'Site', 'section'=>'Section', 'name'=>'Item', 'unit'=>'Unit', 'qty'=>'Planned quantity'],
        'indent'=>['boq'=>'BOQ item', 'qty'=>'Required quantity', 'due'=>'Required by'],
        'quote'=>['indent'=>'Approved indent', 'name'=>'Supplier', 'rate'=>'Unit rate', 'tax'=>'Tax %', 'freight'=>'Freight', 'due'=>'Delivery date', 'note'=>'Payment terms'],
        'po'=>['quote'=>'Selected quotation', 'qty'=>'Order quantity', 'ref'=>'PO number', 'due'=>'Expected delivery'],
        'follow'=>['po'=>'Purchase order', 'note'=>'Follow-up notes', 'due'=>'Next follow-up'],
        'cancel'=>['po'=>'Purchase order', 'qty'=>'Quantity to cancel', 'note'=>'Reason', 'reorder'=>'Return to purchasing'],
        'grn'=>['po'=>'Purchase order', 'ref'=>'GRN number', 'due'=>'Receipt date', 'qty'=>'Accepted quantity', 'reject'=>'Rejected quantity'],
        'invoice'=>['name'=>'Supplier', 'ref'=>'Invoice number', 'due'=>'Invoice date', 'qty'=>'Total invoice quantity'],
        'match'=>['invoice'=>'Supplier invoice', 'grn'=>'Approved GRN', 'qty'=>'Invoice quantity', 'rate'=>'Invoice unit rate', 'note'=>'Variance explanation'],
    ];
    private const PAGE_VERSION = '1.6.0';
    private const PAGE_SLUGS = [
        'boq_form'=>'create-boq', 'boq_items'=>'boq-item-master',
        'indent_master'=>'indent-master', 'indent_items'=>'indents-item-master',
        'quote_form'=>'supplier-quotation-form', 'choose_form'=>'choose-supplier-form', 'choose_master'=>'choose-supplier-master',
        'po_form'=>'create-purchase-order', 'po_items'=>'purchase-order-items',
        'grn_form'=>'goods-receipt-form', 'grn_items'=>'goods-receipt-items', 'grn_slip'=>'upload-grn-slip',
        'employee'=>'employee-master', 'item'=>'item-master',
        'category'=>'item-categories', 'item_type'=>'item-type', 'supplier'=>'supplier-master',
        'home'=>'frontier', 'site'=>'site-master', 'boq'=>'boq', 'indent'=>'indents',
        'quote'=>'quotation-comparison', 'po'=>'purchase-orders', 'follow'=>'delivery-follow-up',
        'cancel'=>'cancel-po-items', 'grn'=>'goods-receipt-notes', 'stock'=>'received-stock',
        'invoice'=>'supplier-invoices', 'match'=>'invoice-matching',
    ];
    private const EMPLOYEE_ROLES = [
        'frontier_requester'=>'Frontier Requester',
        'frontier_approver'=>'Frontier Indent Approver',
        'frontier_buyer'=>'Frontier Buyer',
        'frontier_receiver'=>'Frontier Receiving',
        'frontier_accounts'=>'Frontier Accounts',
    ];
    private static $front = false;

    private const FILES = ['quote'=>'Quotation file', 'grn'=>'Signed GRN slip', 'invoice'=>'Invoice copy'];

    public static function boot() {
        add_action('admin_init', [self::class, 'installPages']);
        add_action('template_redirect', [self::class, 'protectPage'], 0);
        add_filter('template_include', [self::class, 'workspaceTemplate'], 99);
        add_filter('render_block_core/navigation-link', [self::class, 'legacyNavigationBlock'], 10, 2);
        add_filter('render_block_core/navigation-submenu', [self::class, 'legacyNavigationBlock'], 10, 2);
        add_filter('wp_list_pages_excludes', [self::class, 'excludeLegacyPages']);
        add_filter('wp_nav_menu_objects', [self::class, 'filterClassicNavigation']);
        add_shortcode('frontier_view', [self::class, 'shortcode']);
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_frontier_save', [self::class, 'save']);
        add_action('admin_post_frontier_file', [self::class, 'download']);
    }

    private static function isLegacyNavigationUrl($url) {
        $parts = wp_parse_url($url);
        $legacy = wp_parse_url(home_url('/site-management/'));
        return isset($parts['path'], $legacy['path'])
            && untrailingslashit($parts['path']) === untrailingslashit($legacy['path'])
            && (!isset($parts['host']) || strcasecmp($parts['host'], $legacy['host']) === 0);
    }

    public static function legacyNavigationBlock($content,$block) {
        if (is_admin()) {
            return $content;
        }
        $attributes = $block['attrs'] ?? [];
        $url = $attributes['url'] ?? '';
        if (($attributes['type'] ?? '') === 'page' && !empty($attributes['id'])) {
            $url = get_permalink((int)$attributes['id']) ?: $url;
        }
        return self::isLegacyNavigationUrl($url) ? '' : $content;
    }

    public static function excludeLegacyPages($excluded) {
        if (is_admin()) {
            return $excluded;
        }
        $page = get_page_by_path('site-management');
        if (!$page) {
            return $excluded;
        }
        $excluded[] = (int)$page->ID;
        // Page-list menus must exclude descendants before the tree is built.
        foreach (get_pages(['child_of'=>(int)$page->ID]) ?: [] as $child) {
            $excluded[] = (int)$child->ID;
        }
        return array_values(array_unique($excluded));
    }

    public static function filterClassicNavigation($items) {
        if (is_admin()) {
            return $items;
        }
        $hidden = [];
        do {
            $count = count($hidden);
            foreach ($items as $item) {
                if (self::isLegacyNavigationUrl($item->url) || isset($hidden[(int)$item->menu_item_parent])) {
                    $hidden[(int)$item->ID] = true;
                }
            }
        } while (count($hidden) !== $count);
        return array_values(array_filter($items, static function ($item) use ($hidden) {
            return !isset($hidden[(int)$item->ID]);
        }));
    }

    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
          id bigint unsigned NOT NULL AUTO_INCREMENT,
          kind varchar(20) NOT NULL,
          status varchar(20) NOT NULL,
          details longtext NOT NULL,
          document longblob NULL,
          mime varchar(80) NOT NULL DEFAULT '',
          filename varchar(255) NOT NULL DEFAULT '',
          PRIMARY KEY  (id),
          KEY kind (kind)
        ) ENGINE=InnoDB $charset;");
        $roles = [
            'frontier_requester'=>['Frontier Requester','sites','boq','indent'],
            'frontier_approver'=>['Frontier Indent Approver','approve'],
            'frontier_buyer'=>['Frontier Buyer','quote','po','follow','cancel'],
            'frontier_receiver'=>['Frontier Receiving','grn','stock'],
            'frontier_accounts'=>['Frontier Accounts','invoice','match'],
        ];
        foreach ($roles as $key=>$definition) {
            $label = array_shift($definition);
            add_role($key,$label,['read'=>true,'frontier_read'=>true]);
            $role = get_role($key);
            foreach ($definition as $capability) {
                $role->add_cap('frontier_'.$capability);
            }
        }
        self::installPages();
    }

    private static function views() {
        return ['home'=>'Dashboard'] + array_merge(...array_values(self::GROUPS)) + ['stock'=>'Received Stock'];
    }

    public static function installPages() {
        if (!current_user_can('manage_options') || get_option('frontier_page_version') === self::PAGE_VERSION) {
            return;
        }
        $pages = (array)get_option('frontier_pages', []);
        $ready = true;
        foreach (self::views() as $kind=>$title) {
            if ($kind !== 'home' && empty($pages['home'])) {
                $ready = false;
                break;
            }
            $id = absint($pages[$kind] ?? 0);
            if (!$id || get_post_meta($id,'_frontier_view',true) !== $kind) {
                $existing = get_posts([
                    'post_type'=>'page', 'post_status'=>'any', 'numberposts'=>1,
                    'fields'=>'ids', 'meta_key'=>'_frontier_view', 'meta_value'=>$kind,
                    'suppress_filters'=>true,
                ]);
                $id = $existing ? (int)$existing[0] : 0;
            }
            if (!$id) {
                $id = wp_insert_post([
                    'post_type'=>'page', 'post_status'=>'publish', 'post_title'=>$title,
                    'post_name'=>self::PAGE_SLUGS[$kind], 'post_parent'=>$kind === 'home' ? 0 : $pages['home'],
                    'post_content'=>'[frontier_view]', 'meta_input'=>['_frontier_view'=>$kind],
                    'comment_status'=>'closed', 'ping_status'=>'closed',
                ],true);
            }
            if (is_wp_error($id) || !$id) {
                unset($pages[$kind]);
                $ready = false;
                continue;
            }
            $pages[$kind] = (int)$id;
        }
        update_option('frontier_pages',$pages,false);
        if ($ready) {
            update_option('frontier_page_version',self::PAGE_VERSION,false);
        }
    }

    private static function requestedFrontView() {
        return is_page() ? (string)get_post_meta(get_queried_object_id(),'_frontier_view',true) : '';
    }

    public static function protectPage() {
        $kind = self::requestedFrontView();
        if (!isset(self::views()[$kind])) {
            return;
        }
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE',true);
        }
        nocache_headers();
        if (!is_user_logged_in()) {
            auth_redirect();
        }
        if (!self::canView($kind)) {
            wp_die('Access denied.', '', ['response'=>403]);
        }
        if ($kind !== 'home' && is_front_page() && ($dashboard = self::frontUrl('home'))) {
            wp_safe_redirect($dashboard);
            exit;
        }
        show_admin_bar(false);
        remove_action('wp_head', '_admin_bar_bump_cb');
        wp_enqueue_style('frontier-pages',plugins_url('pages.css',__FILE__),[],self::PAGE_VERSION);
    }

    public static function workspaceTemplate($template) {
        return isset(self::views()[self::requestedFrontView()]) ? __DIR__.'/workspace.php' : $template;
    }

    public static function workspace() {
        $kind = self::requestedFrontView();
        $views = self::views();
        if (!isset($views[$kind]) || !self::canView($kind)) {
            return;
        }
        self::$front = true;
        ?>
        <a class="frontier-skip" href="#frontier-main">Skip to content</a>
        <div class="frontier-shell">
            <div class="frontier-main-column">
                <header class="frontier-topbar">
                    <span><?php echo esc_html($views[$kind]); ?></span>
                    <?php if ($kind === 'home') : ?>
                        <a href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>">Sign out</a>
                    <?php endif; ?>
                </header>
                <main id="frontier-main" class="frontier-main">
                    <?php if ($kind !== 'home') : ?>
                        <a class="frontier-back" href="<?php echo esc_url(self::url('home')); ?>">← Back to Dashboard</a>
                    <?php endif; ?>
                    <h1><?php echo esc_html($views[$kind]); ?></h1>
                    <?php self::render($kind); ?>
                </main>
            </div>
        </div>
        <?php
    }

    public static function shortcode() {
        $kind = self::requestedFrontView();
        if (!is_user_logged_in() || !isset(self::views()[$kind]) || !self::canView($kind)
            || (int)get_the_ID() !== (int)get_queried_object_id()) {
            return '';
        }
        self::$front = true;
        ob_start();
        self::render($kind);
        return ob_get_clean();
    }

    private static function canView($kind) {
        if ($kind === 'choose_form' && !self::can('quote')) {
            return false;
        }
        if ($kind === 'grn_slip' && !self::can('grn')) {
            return false;
        }
        if (isset(self::MASTERS[$kind])) {
            return current_user_can('manage_options');
        }
        return self::can('read') && (!in_array($kind,['invoice','match'],true) || self::can('invoice') || self::can('match'));
    }

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'frontier_records';
    }

    private static function can($capability) {
        return current_user_can('manage_options') || current_user_can('frontier_'.$capability);
    }

    public static function menu() {
        $cap = current_user_can('manage_options') ? 'manage_options' : 'frontier_read';
        add_menu_page('Frontier','Frontier',$cap,'frontier',[self::class,'page'],'dashicons-clipboard');
        foreach (self::views() as $kind=>$label) {
            if (!self::canView($kind)) {
                continue;
            }
            if ($kind === 'home') {
                add_submenu_page('frontier','Dashboard','Dashboard',$cap,'frontier');
                continue;
            }
            $menuLabel = isset(self::MASTERS[$kind]) ? 'Master · '.$label : $label;
            add_submenu_page('frontier',$label,$menuLabel,$cap,'frontier-'.str_replace('_','-',$kind),static function () use ($kind) {
                self::render($kind);
            });
        }
    }

    private static function rows() {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT id, kind, status, details, filename FROM '.self::table().' ORDER BY id DESC', ARRAY_A);
        if ($rows === null) {
            throw new RuntimeException('Frontier could not read procurement records.');
        }
        foreach ($rows as &$row) {
            $row['data'] = json_decode($row['details'],true);
        }
        return array_column($rows,null,'id');
    }

    private static function sum($rows,$kind,$parent,$id,$field='qty',$status='') {
        $sum = 0;
        foreach ($rows as $row) {
            if ($row['kind'] === $kind && (!$status || $row['status'] === $status)
                && (int)($row['data'][$parent] ?? 0) === (int)$id) {
                $sum += (float)($row['data'][$field] ?? 0);
            }
        }
        return round($sum,3);
    }

    private static function balance($rows,$po) {
        $id = $po['id'];
        return round($po['data']['qty'] - self::sum($rows,'cancel','po',$id)
            - self::sum($rows,'grn','po',$id),3);
    }

    private static function available($rows,$indent) {
        $used = 0;
        foreach ($rows as $po) {
            if ($po['kind'] === 'po' && $po['status'] !== 'cancelled'
                && (int)$po['data']['indent'] === (int)$indent['id']) {
                $used += $po['data']['qty'] - self::sum($rows,'cancel','po',$po['id'],'released');
            }
        }
        return round($indent['data']['qty'] - $used,3);
    }

    private static function label($row,$rows) {
        $data = $row['data'];
        $labels = array_intersect_key($data,array_flip(['name','ref','section']));
        foreach (['site','boq','indent','po'] as $parent) {
            if (isset($data[$parent],$rows[$data[$parent]])) {
                $labels[] = self::label($rows[$data[$parent]],$rows);
                break;
            }
        }
        return '#'.$row['id'].($labels ? ' · '.implode(' · ',$labels) : '');
    }

    private static function eligible($rows,$kind,$field) {
        return array_filter($rows, static function ($row) use ($rows,$kind,$field) {
            if ($row['kind'] !== $field) {
                return false;
            }
            switch ($field) {
                case 'indent': return $row['status'] === 'approved';
                case 'quote': return $row['status'] === 'selected' && self::available($rows,$rows[$row['data']['indent']]) > 0;
                case 'po': return $row['status'] === 'issued' && ($kind === 'follow' || self::balance($rows,$row) > 0);
                case 'grn': return $row['status'] === 'approved' && $row['data']['qty'] > self::sum($rows,'match','grn',$row['id']);
                default: return true;
            }
        });
    }

    public static function page() {
        $kind = sanitize_key(wp_unslash($_GET['view'] ?? 'home'));
        self::render(isset(self::views()[$kind]) ? $kind : 'home');
    }

    private static function render($kind) {
        if (!self::canView($kind)) {
            wp_die('Access denied.', '', ['response'=>403]);
        }
        $tabs = self::views();
        echo '<div class="wrap frontier-pages">';
        if (!self::$front) {
            echo '<h1>'.esc_html($tabs[$kind]).'</h1>';
        }
        if ($kind === 'home') {
            if (!self::$front && ($frontUrl = self::frontUrl('home'))) {
                echo '<p><a class="button" href="'.esc_url($frontUrl).'">Open Frontier on the site</a></p>';
            }
            if (self::$front) {
                echo '<div class="frontier-overview">';
            }
            foreach (self::GROUPS as $group=>$views) {
                $visible = array_filter(array_keys($views),[self::class,'canView']);
                if (!$visible) {
                    continue;
                }
                echo '<section class="frontier-card-'.esc_attr(sanitize_title($group)).'"><h2>'.esc_html($group).'</h2><ul>';
                foreach ($visible as $key) {
                    echo '<li><a href="'.esc_url(self::url($key)).'">'.esc_html($views[$key]).'</a></li>';
                }
                echo '</ul></section>';
            }
            echo self::$front ? '</div></div>' : '</div>';
            return;
        }
        if (!self::$front) {
            echo '<p><a href="'.esc_url(self::url('home')).'">Back to Dashboard</a></p>';
        }
        if (isset($_GET['saved'])) {
            echo '<div class="notice notice-success" role="status"><p>Saved.</p></div>';
        }
        self::$screen = $kind;
        [$kind,$mode] = self::SCREENS[$kind] ?? [$kind,'combined'];
        $rows = self::rows();
        $edit = $rows[absint($_GET['edit'] ?? 0)] ?? null;
        $editing = $edit && $edit['kind'] === $kind && ((isset(self::MASTERS[$kind]) || $kind === 'site')
            || ($kind === 'indent' && in_array($edit['status'],['draft','returned','approved'],true)));
        if ($kind === 'stock') {
            self::stock($rows);
            echo '</div>';
            return;
        }
        if (in_array($mode,['choose','selected'],true)) {
            $url = self::url(self::$screen);
            $query = [];
            parse_str((string)wp_parse_url($url,PHP_URL_QUERY),$query);
            echo '<form method="get" action="'.esc_url(remove_query_arg(array_keys($query),$url)).'">';
            foreach ($query as $key=>$value) {
                echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr($value).'">';
            }
            self::field($rows,'quote','indent','Compare quotations for',absint($_GET['indent'] ?? 0));
            echo '<button class="button">Filter</button> <a href="'.esc_url($url).'">Show all</a></form>';
        }
        $canAdd = in_array($mode,['form','combined','register'],true) && self::can($kind === 'site' ? 'sites' : $kind);
        $showForm = $canAdd && $mode !== 'register' && ($editing || isset($_GET['add']));
        if ($canAdd && !$showForm) {
            $formScreen = $mode === 'register' ? ([
                'boq'=>'boq_form','indent'=>'indent','quote'=>'quote_form','po'=>'po_form','grn'=>'grn_form',
            ][$kind] ?? self::$screen) : self::$screen;
            echo '<p><a class="button frontier-add" href="'.esc_url(add_query_arg('add',1,self::url($formScreen))).'">Add</a></p>';
        }
        if ($showForm) {
            self::formStart($kind,$editing ? 'edit' : 'create',$editing ? $edit['id'] : 0);
            if (self::$front) {
                echo '<h2>'.esc_html($editing ? 'Edit record' : ($kind === 'site' ? 'Add site' : 'Add record')).'</h2><div class="frontier-fields">';
            }
            foreach (self::FIELDS[$kind] as $field=>$label) {
                self::field($rows,$kind,$field,$label,$editing ? ($edit['data'][$field] ?? '') : '');
            }
            if (isset(self::FILES[$kind])) {
                self::uploadField($kind,$kind === 'invoice');
            }
            if (self::$front) {
                echo '</div>';
            }
            self::submitButton($editing ? 'Save changes' : ($kind === 'indent' ? 'Save draft' : 'Save'),true);
            echo ' <a class="button" href="'.esc_url(self::url(self::$screen)).'">Cancel</a></form>';
        }
        if ($mode === 'form') {
            $register = $kind === 'indent' ? 'indent_master' : $kind;
            echo '<p><a href="'.esc_url(self::url($register)).'">'.esc_html($tabs[$register]).'</a></p>';
            if ($showForm) {
                echo '</div>';
                return;
            }
        }
        if ($mode === 'items') {
            self::renderItems($rows,$kind);
            echo '</div>';
            return;
        }
        echo '<div class="frontier-table"><table class="widefat striped"><thead><tr><th>Record</th><th>Status</th><th>Details</th><th>Actions</th></tr></thead><tbody>';
        $count = 0;
        foreach ($rows as $row) {
            if ($row['kind'] !== $kind && !($kind === 'follow' && $row['kind'] === 'po' && $row['status'] === 'issued' && self::balance($rows,$row) > 0)) {
                continue;
            }
            if ($row['kind'] === 'invoice' && !self::can('invoice') && !self::can('match')) {
                continue;
            }
            $indentFilter = absint($_GET['indent'] ?? 0);
            if ($kind === 'quote' && $indentFilter && (int)$row['data']['indent'] !== $indentFilter) {
                continue;
            }
            if (($mode === 'choose' && $row['status'] === 'selected')
                || ($mode === 'selected' && $row['status'] !== 'selected')) {
                continue;
            }
            $count++;
            self::displayRow($row,$rows);
        }
        if (!$count) {
            echo '<tr><td colspan="4">No records yet.</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    private static function renderItems($rows,$kind) {
        $columns = [
            'boq'=>['site','section','name','unit','qty'],
            'indent'=>['boq','qty','due'],
            'po'=>['ref','quote','qty','due'],
            'grn'=>['ref','po','qty','reject','due'],
        ][$kind];
        echo '<div class="frontier-table"><table class="widefat striped"><thead><tr>';
        foreach ($columns as $field) {
            echo '<th scope="col">'.esc_html(self::FIELDS[$kind][$field]).'</th>';
        }
        echo '<th scope="col">Status</th></tr></thead><tbody>';
        $count = 0;
        foreach ($rows as $row) {
            if ($row['kind'] !== $kind) {
                continue;
            }
            $count++;
            echo '<tr>';
            foreach ($columns as $field) {
                $value = $row['data'][$field] ?? '';
                if (isset(self::FIELDS[$field],$rows[$value])) {
                    $value = self::label($rows[$value],$rows);
                } elseif ($field === 'due' && $value !== '') {
                    $value = implode('/',array_reverse(explode('-',$value)));
                }
                echo '<td>'.esc_html((string)$value).'</td>';
            }
            echo '<td>'.esc_html(ucwords(str_replace('_',' ',$row['status']))).'</td></tr>';
        }
        if (!$count) {
            echo '<tr><td colspan="'.(count($columns)+1).'">No records yet.</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private static function stock($rows) {
        $received = [];
        foreach ($rows as $row) {
            if ($row['kind'] !== 'grn' || $row['status'] !== 'approved') {
                continue;
            }
            $po = $rows[$row['data']['po']];
            $boq = $rows[$rows[$po['data']['indent']]['data']['boq']];
            $received[$boq['id']] = ($received[$boq['id']] ?? 0) + $row['data']['qty'];
        }
        echo '<div class="frontier-table"><table class="widefat striped"><thead><tr><th>BOQ item and site</th><th>Accepted stock received</th></tr></thead><tbody>';
        foreach ($received as $id=>$quantity) {
            echo '<tr><td>'.esc_html(self::label($rows[$id],$rows)).'</td><td>'.esc_html(round($quantity,3).' '.$rows[$id]['data']['unit']).'</td></tr>';
        }
        if (!$received) {
            echo '<tr><td colspan="2">No stock receipts approved yet.</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private static function frontUrl($kind) {
        $pages = (array)get_option('frontier_pages',[]);
        $id = absint($pages[$kind] ?? 0);
        return $id && get_post_status($id) === 'publish' ? get_permalink($id) : false;
    }

    private static function url($kind) {
        if (self::$front && ($url = self::frontUrl($kind))) {
            return $url;
        }
        return add_query_arg('page',$kind === 'home' ? 'frontier' : 'frontier-'.str_replace('_','-',$kind),admin_url('admin.php'));
    }

    private static function submitButton($label,$primary=false) {
        echo '<button type="submit" class="button'.($primary ? ' button-primary' : '').'">'.esc_html($label).'</button>';
    }

    private static function formStart($kind,$action,$id=0) {
        $class = in_array($action,['create','edit'],true) ? ' class="frontier-entry"' : '';
        echo '<form'.$class.' method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('frontier_save');
        foreach (['action'=>'frontier_save','kind'=>$kind,'operation'=>$action,'id'=>$id,'screen'=>self::$screen,'surface'=>self::$front ? 'front' : 'admin'] as $key=>$value) {
            echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr($value).'">';
        }
    }

    private static function uploadField($kind,$required=false) {
        echo '<p><label>'.esc_html(self::FILES[$kind]).' <input type="file" name="frontier_document" accept=".pdf,.png,.jpg,.jpeg" '.($required ? 'required' : '').'></label></p>';
    }

    private static function optionalField($kind,$field) {
        return in_array($field,['reject','tax','freight'],true)
            || ($field === 'note' && in_array($kind,['quote','match'],true))
            || ($kind === 'supplier' && !in_array($field,['ref','name'],true))
            || ($kind === 'site' && in_array($field,['telegram','employee'],true));
    }

    private static function field($rows,$kind,$field,$label,$value) {
        echo '<p><label>'.esc_html($label).' ';
        if ($kind === 'employee' && $field === 'role') {
            echo '<select name="role" required><option value="">Select</option>';
            foreach (self::EMPLOYEE_ROLES as $key=>$name) {
                echo '<option value="'.esc_attr($key).'" '.selected($value,$key,false).'>'.esc_html($name).'</option>';
            }
            echo '</select>';
        } elseif ($kind === 'employee' && $field === 'password') {
            echo '<input type="password" name="password" autocomplete="new-password" maxlength="4096">';
            echo '<small>Required for a new login. Leave blank to keep an existing password.</small>';
        } elseif (isset(self::FIELDS[$field])) {
            echo '<select name="'.esc_attr($field).'"'.(self::optionalField($kind,$field) ? '' : ' required').'><option value="">Select</option>';
            foreach (self::eligible($rows,$kind,$field) as $row) {
                echo '<option value="'.esc_attr($row['id']).'" '.selected($value,$row['id'],false).'>'.esc_html(self::label($row,$rows)).'</option>';
            }
            echo '</select>';
        } elseif ($field === 'reorder') {
            echo '<input type="checkbox" name="reorder" value="1">';
        } else {
            $numeric = in_array($field,['qty','rate','tax','freight','reject'],true);
            $type = $numeric ? 'number' : (['due'=>'date','email'=>'email','mobile'=>'tel','contact'=>'tel','alternate'=>'tel'][$field] ?? 'text');
            $optional = self::optionalField($kind,$field);
            printf('<input type="%s" name="%s" value="%s" %s %s>',esc_attr($type),esc_attr($field),esc_attr($value),
                $numeric ? 'step="0.001" min="0"' : 'maxlength="200"', $optional ? '' : 'required');
        }
        echo '</label></p>';
    }

    private static function actions($row,$rows) {
        $kind = $row['kind'];
        $status = $row['status'];
        $mode = self::SCREENS[self::$screen][1] ?? 'combined';
        if (in_array($mode,['items','selected'],true)) {
            return;
        }
        if ((isset(self::MASTERS[$kind]) || $kind === 'site') && self::can($kind === 'site' ? 'sites' : $kind)) {
            echo '<a class="button" href="'.esc_url(add_query_arg('edit',$row['id'],self::url($kind))).'">Edit</a>';
        }
        if ($kind === 'indent') {
            if (self::can('indent') && in_array($status,['draft','returned','approved'],true)) {
                echo '<a class="button" href="'.esc_url(add_query_arg('edit',$row['id'],self::url($kind))).'">Edit</a> ';
                if ($status !== 'approved') {
                    self::button($row,'submit','Submit for approval');
                }
            }
            if ($status === 'pending' && self::can('approve')) {
                self::button($row,'approve','Approve');
                self::button($row,'return','Return',true);
            }
        }
        if ($kind === 'quote' && $mode === 'choose' && self::can('quote') && $status !== 'selected') {
            echo '<details><summary>Choose supplier</summary>';
            self::button($row,'select','Save supplier',true);
            echo '</details>';
        }
        if ($kind === 'po' && self::can('po')) {
            if ($status === 'draft') {
                self::button($row,'issue','Issue PO');
                self::button($row,'delete','Delete draft');
            } elseif ($status === 'issued' && !self::linked($rows,$row['id'])) {
                self::button($row,'cancel_po','Cancel PO',true);
            }
        }
        if ($kind === 'grn' && $mode === 'slip' && self::can('grn')) {
            echo '<details><summary>Upload GRN Slip</summary>';
            self::formStart($kind,'attach',$row['id']);
            self::uploadField($kind,true);
            self::submitButton('Upload slip');
            echo '</form></details>';
        }
        if ($kind === 'grn' && $mode === 'register' && self::can('stock') && $status === 'pending') {
            self::button($row,'approve_grn','Approve and post stock');
        }
    }

    private static function button($row,$operation,$label,$reason=false) {
        self::formStart($row['kind'],$operation,$row['id']);
        if ($reason) {
            echo '<label>Reason <input name="reason" required maxlength="200"></label> ';
        }
        self::submitButton($label);
        echo '</form>';
    }

    private static function displayRow($row,$rows) {
        $kind = $row['kind'];
        $data = $row['data'];
        $messages = [];
        if (in_array($kind,['supplier','site'],true) && !empty($data['ref'])) {
            $label = $kind === 'site' ? 'Site Number with Name' : 'Suppliers Name with Code';
            $messages[] = $label.' · '.$data['ref'].' - '.$data['name'];
        }
        foreach (self::FIELDS[$kind] as $field=>$label) {
            if (!isset($data[$field]) || $data[$field] === '') {
                continue;
            }
            $value = $data[$field];
            if (isset(self::FIELDS[$field],$rows[$value])) {
                $value = self::label($rows[$value],$rows);
            } elseif ($field === 'role') {
                $value = self::EMPLOYEE_ROLES[$value] ?? $value;
            } elseif ($field === 'due') {
                $value = implode('/',array_reverse(explode('-',$value)));
            } elseif ($field === 'reorder') {
                $value = $value ? 'Yes' : 'No';
            }
            $messages[] = $label.' · '.$value;
        }
        foreach (['reason','selection_reason'] as $field) {
            if (!empty($data[$field])) {
                $messages[] = 'Reason · '.$data[$field];
            }
        }
        if ($kind === 'po' && $row['status'] === 'issued') {
            $id = $row['id'];
            $messages[] = 'Received · '.self::sum($rows,'grn','po',$id,'qty','approved');
            $messages[] = 'Pending GRN approval · '.self::sum($rows,'grn','po',$id,'qty','pending');
            $messages[] = 'Invoiced · '.self::sum($rows,'match','po',$id);
            $messages[] = 'Cancelled · '.self::sum($rows,'cancel','po',$id);
            $messages[] = 'Open to receive · '.self::balance($rows,$row);
            if (self::balance($rows,$row) > 0) {
                $today = new DateTimeImmutable(current_time('Y-m-d'));
                $due = new DateTimeImmutable($data['due']);
                $messages[] = 'Overdue days · '.max(0,(int)$due->diff($today)->format('%r%a'));
            }
            foreach ($rows as $follow) {
                if ($follow['kind'] === 'follow' && (int)$follow['data']['po'] === (int)$id) {
                    $messages[] = 'Latest follow-up · '.$follow['data']['note'].' · Next '.implode('/',array_reverse(explode('-',$follow['data']['due'])));
                    break;
                }
            }
        }
        if ($kind === 'grn') {
            $messages[] = $row['filename'] ? 'GRN slip attached' : 'Slip pending';
        }
        $status = $row['status'];
        if ($kind === 'invoice') {
            $matched = self::sum($rows,'match','invoice',$row['id']);
            $messages[] = 'Matched quantity · '.$matched;
            $status = $matched >= $data['qty'] ? 'matched' : ($matched > 0 ? 'partially_matched' : 'pending_match');
        }
        echo '<tr><td>'.esc_html(self::label($row,$rows)).'</td><td>'.esc_html(ucwords(str_replace('_',' ',$status))).'</td><td>';
        foreach ($messages as $message) {
            echo esc_html($message).'<br>';
        }
        if ($row['filename'] && self::canFile($kind)) {
            $url = add_query_arg(['action'=>'frontier_file','id'=>$row['id']],admin_url('admin-post.php'));
            echo '<a href="'.esc_url(wp_nonce_url($url,'frontier_file_'.$row['id'])).'">'.esc_html($row['filename']).'</a>';
        }
        echo '</td><td>';
        self::actions($row,$rows);
        echo '</td></tr>';
    }

    private static function linked($rows,$id) {
        foreach ($rows as $row) {
            if (in_array($row['kind'],['grn','match','cancel'],true) && (int)$row['data']['po'] === (int)$id) {
                return true;
            }
        }
        return false;
    }

    private static function requireRule($condition,$message) {
        if (!$condition) {
            throw new DomainException($message);
        }
    }

    private static function input($key) {
        $value = $_POST[$key] ?? '';
        return is_string($value) ? sanitize_text_field(wp_unslash($value)) : '';
    }

    private static function readFields($rows,$kind) {
        $data = [];
        foreach (self::FIELDS[$kind] as $field=>$label) {
            if ($field === 'password') {
                continue;
            }
            $value = self::input($field);
            if ($value === '' && self::optionalField($kind,$field) && isset(self::FIELDS[$field])) {
                $data[$field] = '';
                continue;
            }
            if (isset(self::FIELDS[$field])) {
                $record = self::eligible($rows,$kind,$field)[absint($value)] ?? null;
                self::requireRule($record,'Choose a valid '.$label.'.');
                $value = (int)$record['id'];
            } elseif (in_array($field,['qty','rate','tax','freight','reject'],true)) {
                if ($value === '' && in_array($field,['tax','freight','reject'],true)) {
                    $value = '0';
                }
                self::requireRule(preg_match('/^\d{1,10}(?:\.\d{1,3})?$/D',$value),'Enter a valid '.$label.'.');
                $value = (float)$value;
                if ($field === 'qty' && $kind !== 'grn') {
                    self::requireRule($value > 0,'Quantity must be greater than zero.');
                }
                if ($field === 'tax') {
                    self::requireRule($value <= 100,'Tax must be between 0 and 100 percent.');
                }
            } elseif ($field === 'due') {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d',$value);
                self::requireRule($date && $date->format('Y-m-d') === $value,'Enter a valid date.');
            } elseif ($field === 'reorder') {
                $value = $value === '1';
            } else {
                $optional = self::optionalField($kind,$field);
                if ($field === 'email' && $value !== '') {
                    self::requireRule(is_email($value),'Enter a valid email address.');
                }
                self::requireRule(($value !== '' || $optional) && strlen($value) <= 200,'Check '.$label.' (maximum 200 bytes).');
            }
            $data[$field] = $value;
        }
        return $data;
    }

    private static function document($required=false) {
        $file = $_FILES['frontier_document'] ?? [];
        if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
            self::requireRule(!$required,'Attach a PDF, PNG or JPEG document.');
            return [];
        }
        self::requireRule($file['error'] === UPLOAD_ERR_OK && is_uploaded_file($file['tmp_name']) && $file['size'] > 0 && $file['size'] <= 3*1024*1024,'Upload a document up to 3 MB.');
        $filename = sanitize_file_name($file['name']);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $types = ['pdf'=>'application/pdf','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg'];
        $ext = strtolower(pathinfo($filename,PATHINFO_EXTENSION));
        self::requireRule(isset($types[$ext]) && $types[$ext] === $mime,'Choose a valid PDF, PNG or JPEG file.');
        $bytes = file_get_contents($file['tmp_name']);
        self::requireRule($bytes !== false,'The attachment could not be read.');
        return ['document'=>base64_encode($bytes),'mime'=>$mime,'filename'=>$filename];
    }

    private static function canFile($kind) {
        if ($kind === 'invoice') {
            return self::can('invoice') || self::can('match');
        }
        return self::can('read') && isset(self::FILES[$kind]);
    }

    public static function download() {
        global $wpdb;
        $id = absint($_GET['id'] ?? 0);
        check_admin_referer('frontier_file_'.$id);
        $file = $wpdb->get_row($wpdb->prepare('SELECT kind, document, mime, filename FROM '.self::table().' WHERE id = %d',$id),ARRAY_A);
        if (!$file || !$file['filename'] || !self::canFile($file['kind'])) {
            wp_die('Document unavailable.', '', ['response'=>404]);
        }
        nocache_headers();
        header('X-Content-Type-Options: nosniff');
        header('Content-Type: '.$file['mime']);
        header('Content-Disposition: attachment; filename="frontier-'.$id.'.'.pathinfo($file['filename'],PATHINFO_EXTENSION).'"');
        echo base64_decode($file['document'],true);
        exit;
    }

    private static function validateMaster($rows,$kind,$data,$id=0) {
        $key = $kind === 'employee' ? 'login' : (isset($data['ref']) ? 'ref' : 'name');
        foreach ($rows as $row) {
            if ($row['kind'] === $kind && (int)$row['id'] !== (int)$id) {
                if ($kind === 'item' && ((int)($row['data']['category'] ?? 0) !== (int)$data['category']
                    || (int)($row['data']['item_type'] ?? 0) !== (int)$data['item_type'])) {
                    continue;
                }
                self::requireRule(strcasecmp($row['data'][$key] ?? '',$data[$key]) !== 0,
                    'This '.(['ref'=>'code or number','login'=>'user ID'][$key] ?? 'name').' already exists.');
            }
        }
    }

    private static function validateNew($rows,$kind,&$data) {
        if (isset(self::MASTERS[$kind]) || $kind === 'site') {
            self::validateMaster($rows,$kind,$data);
        }
        switch ($kind) {
            case 'po':
                $quote = $rows[$data['quote']]['data'];
                $data['indent'] = $quote['indent'];
                $data['name'] = $quote['name'];
                $data['rate'] = $quote['rate'];
                self::requireRule($data['qty'] <= self::available($rows,$rows[$data['indent']]),'The order exceeds the approved indent balance.');
                break;
            case 'grn':
                $po = $rows[$data['po']];
                self::requireRule($data['qty'] + $data['reject'] > 0,'Enter a received quantity.');
                self::requireRule(round($data['qty'] + $data['reject'],3) <= self::balance($rows,$po),'Delivery exceeds the open PO quantity.');
                break;
            case 'cancel':
                $po = $rows[$data['po']];
                self::requireRule($data['qty'] <= self::balance($rows,$po),'Cancellation exceeds the available PO quantity.');
                $data['released'] = $data['reorder'] ? $data['qty'] : 0;
                break;
            case 'match':
                $grn = $rows[$data['grn']];
                $data['po'] = $grn['data']['po'];
                $po = $rows[$data['po']];
                $invoice = $rows[$data['invoice']];
                self::requireRule(strcasecmp($po['data']['name'],$invoice['data']['name']) === 0,'The invoice and PO suppliers must match.');
                self::requireRule($data['qty'] <= round($grn['data']['qty'] - self::sum($rows,'match','grn',$grn['id']),3),'Invoice quantity exceeds the unmatched GRN quantity.');
                self::requireRule($data['qty'] <= round($invoice['data']['qty'] - self::sum($rows,'match','invoice',$invoice['id']),3),'Quantity exceeds the unmatched invoice balance.');
                self::requireRule($data['rate'] === (float)$po['data']['rate'] || $data['note'] !== '','Explain the variance from the PO rate.');
                break;
        }
        if (isset($data['ref']) && in_array($kind,['po','invoice','grn'],true)) {
            foreach ($rows as $row) {
                $sameSupplier = $kind !== 'invoice' || strcasecmp($data['name'],$row['data']['name'] ?? '') === 0;
                self::requireRule($row['kind'] !== $kind || !$sameSupplier || strcasecmp($data['ref'],$row['data']['ref']) !== 0,'That reference already exists.');
            }
        }
    }

    private static function validateIndent($rows,$data,$id) {
        $used = 0;
        foreach ($rows as $row) {
            if ($row['kind'] === 'indent' && (int)$row['id'] !== (int)$id
                && (int)$row['data']['boq'] === $data['boq'] && in_array($row['status'],['pending','approved'],true)) {
                $used += $row['data']['qty'];
            }
        }
        self::requireRule(round($used + $data['qty'],3) <= $rows[$data['boq']]['data']['qty'],'This exceeds the available BOQ quantity.');
    }

    private static function transition($rows,$row,$action) {
        $kind = $row['kind'];
        $status = $row['status'];
        $data = $row['data'];
        $update = [];
        switch ($action) {
            case 'edit':
                if (isset(self::MASTERS[$kind]) || $kind === 'site') {
                    $fields = self::readFields($rows,$kind);
                    self::validateMaster($rows,$kind,$fields,$row['id']);
                    $data = $fields + $row['data'];
                    break;
                }
                self::requireRule($kind === 'indent' && in_array($status,['draft','returned','approved'],true),'This indent cannot be edited.');
                $newData = self::readFields($rows,$kind);
                if ($status === 'approved') {
                    foreach ($rows as $record) {
                        self::requireRule(!in_array($record['kind'],['po','quote'],true) || (int)$record['data']['indent'] !== (int)$row['id'],'An indent linked to purchasing records is locked.');
                    }
                    self::validateIndent($rows,$newData,$row['id']);
                    $status = 'pending';
                }
                $data = $newData;
                break;
            case 'submit':
                self::requireRule($kind === 'indent' && in_array($status,['draft','returned'],true),'This indent cannot be submitted.');
                self::validateIndent($rows,$data,$row['id']);
                $status = 'pending';
                break;
            case 'approve':
            case 'return':
                self::requireRule($kind === 'indent' && $status === 'pending','This indent is not pending approval.');
                $status = $action === 'approve' ? 'approved' : 'returned';
                break;
            case 'select':
                self::requireRule($kind === 'quote' && $status !== 'selected' && $rows[$data['indent']]['status'] === 'approved','This quotation cannot be selected.');
                $status = 'selected';
                break;
            case 'issue':
                self::requireRule($kind === 'po' && $status === 'draft','Only a draft PO can be issued.');
                $status = 'issued';
                break;
            case 'cancel_po':
                self::requireRule($kind === 'po' && $status === 'issued' && !self::linked($rows,$row['id']),'Use line cancellation for an order with procurement activity.');
                $status = 'cancelled';
                break;
            case 'approve_grn':
                self::requireRule($kind === 'grn' && $status === 'pending','This GRN has already been processed.');
                $status = 'approved';
                break;
            case 'attach':
                self::requireRule($kind === 'grn','Invalid GRN.');
                $update = self::document(true);
                break;
            default:
                throw new DomainException('Unknown action.');
        }
        if (in_array($action,['return','select','cancel_po'],true)) {
            $reason = self::input('reason');
            self::requireRule($reason !== '' && strlen($reason) <= 200,'Enter a reason up to 200 bytes.');
            $data['reason'] = $reason;
        }
        return $update + ['status'=>$status,'details'=>wp_json_encode($data)];
    }

    private static function employeeAccount($data) {
        $linkedId = absint($data['wp_user_id'] ?? 0);
        $account = $linkedId ? get_userdata($linkedId) : null;
        self::requireRule(current_user_can('promote_users') && ($linkedId
            ? current_user_can('edit_user',$linkedId)
            : current_user_can('create_users')), 'You do not have permission to manage employee logins.');
        self::requireRule(isset(self::EMPLOYEE_ROLES[$data['role']]) && get_role($data['role']), 'Choose a Frontier role.');
        self::requireRule(strlen($data['login']) <= 60 && validate_username($data['login']), 'Enter a valid WordPress user ID.');
        if ($linkedId) {
            self::requireRule($account && $account->roles && !array_diff($account->roles,array_keys(self::EMPLOYEE_ROLES))
                && !is_super_admin($linkedId) && $account->user_login === $data['login'],
                'The linked account must keep its existing User ID and a Frontier role.');
        }
        $input = $_POST['password'] ?? '';
        $password = is_string($input) ? wp_unslash($input) : '';
        self::requireRule(($linkedId && $password === '') || (strlen($password) >= 12 && strlen($password) <= 4096
            && trim($password) === $password), 'Enter a password of 12 to 4096 characters without spaces at either end.');
        $user = ['user_login'=>$data['login'], 'user_email'=>$data['email'],
            'display_name'=>$data['name'], 'role'=>$data['role']];
        if ($password !== '') {
            $user['user_pass'] = $password;
        }
        $id = $linkedId ? wp_update_user(['ID'=>$linkedId] + $user) : wp_insert_user($user);
        self::requireRule(!is_wp_error($id), is_wp_error($id) ? $id->get_error_message() : '');
        return (int)$id;
    }

    public static function save() {
        global $wpdb;
        check_admin_referer('frontier_save');
        $kind = sanitize_key(self::input('kind'));
        $action = sanitize_key(self::input('operation'));
        $capability = ['approve'=>'approve','return'=>'approve','approve_grn'=>'stock'][$action] ?? ($kind === 'site' ? 'sites' : $kind);
        if (!isset(self::FIELDS[$kind]) || !self::canView($kind) || !self::can($capability)) {
            wp_die('Access denied.', '', ['response'=>403]);
        }
        $lock = 'frontier_'.md5(DB_NAME.'|'.self::table());
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)',$lock)) !== 1) {
            wp_die('Frontier is busy. Please retry.', '', ['response'=>503]);
        }
        $error = null;
        $transaction = false;
        $accountId = 0;
        try {
            $rows = self::rows();
            if ($kind === 'employee' && in_array($action,['create','edit'],true)) {
                if ($wpdb->query('START TRANSACTION') === false) {
                    throw new RuntimeException('Employee account transaction could not start.');
                }
                $transaction = true;
            }
            if ($action === 'create') {
                $data = self::readFields($rows,$kind);
                self::validateNew($rows,$kind,$data);
                if ($transaction) {
                    $accountId = self::employeeAccount($data);
                    $data['wp_user_id'] = $accountId;
                }
                $status = ['indent'=>'draft','po'=>'draft','grn'=>'pending','quote'=>'received','invoice'=>'pending_match'][$kind] ?? 'recorded';
                $document = isset(self::FILES[$kind]) ? self::document($kind === 'invoice') : [];
                $result = $wpdb->insert(self::table(),$document + ['kind'=>$kind,'status'=>$status,'details'=>wp_json_encode($data)]);
            } else {
                $row = $rows[absint(self::input('id'))] ?? null;
                self::requireRule($row && $row['kind'] === $kind,'Record could not be found.');
                if ($action === 'delete') {
                    self::requireRule($kind === 'po' && $row['status'] === 'draft' && !self::linked($rows,$row['id']),'Only an unused draft PO may be deleted.');
                    $result = $wpdb->delete(self::table(),['id'=>$row['id']]);
                } else {
                    $update = self::transition($rows,$row,$action);
                    if ($transaction) {
                        $data = json_decode($update['details'],true);
                        $accountId = self::employeeAccount($data);
                        $data['wp_user_id'] = $accountId;
                        $update['details'] = wp_json_encode($data);
                    }
                    $result = $wpdb->update(self::table(),$update,['id'=>$row['id']]);
                }
            }
            if ($result === false) {
                throw new RuntimeException('The record could not be saved.');
            }
            if ($transaction && $wpdb->query('COMMIT') === false) {
                throw new RuntimeException('Employee account transaction could not finish.');
            }
            $transaction = false;
        } catch (Throwable $exception) {
            $error = $exception instanceof DomainException ? $exception->getMessage() : 'Frontier could not save the record.';
        } finally {
            if ($transaction) {
                $wpdb->query('ROLLBACK');
            }
            if ($accountId) {
                clean_user_cache($accountId);
            }
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
        }
        if ($error !== null) {
            wp_die(esc_html($error),'Frontier',['response'=>400,'back_link'=>true]);
        }
        self::$front = self::input('surface') === 'front';
        $screen = sanitize_key(self::input('screen'));
        $sameKind = $screen === $kind || (self::SCREENS[$screen][0] ?? '') === $kind;
        $destination = $sameKind && self::canView($screen) ? $screen : $kind;
        wp_safe_redirect(add_query_arg('saved',1,self::url($destination)));
        exit;
    }
}

register_activation_hook(__FILE__,[Frontier::class,'activate']);
Frontier::boot();
