<?php
/**
 * Plugin Name: Frontier
 * Description: Site procurement from BOQ and indent to supplier invoice reconciliation.
 * Version: 1.9.79
 * Requires PHP: 7.4
 */
defined('ABSPATH') || exit;
require_once __DIR__.'/boq-workbook.php';
require_once __DIR__.'/boq-pdf.php';

final class Frontier {
    private const MASTERS = [
        'employee'=>'Employee Master',
        'item'=>'Item Master',
        'category'=>'Item Categories',
        'item_type'=>'Item Type',
        'supplier'=>'Supplier Master',
        'units'=>'Unit',
    ];

    private const GROUPS = [
        'Master' => self::MASTERS + ['site'=>'Site Master','settings'=>'Settings'],
        'BOQ' => ['boq_form'=>'Create BOQ', 'boq'=>'BOQ', 'boq_items'=>'BOQ Item Master'],
        'Indent' => ['pending_indent'=>'Pending Approve Indent', 'indent'=>'Indents Form', 'indent_master'=>'Indent Master', 'indent_items'=>'Indents Item Master'],
        'Supplier Quotation' => ['pending_quote'=>'Pending Supplier Quotation', 'quote_form'=>'Supplier Quotation Form', 'quote'=>'Supplier Quotation Master'],
        'Choose Supplier' => ['pending_choose'=>'Pending Choose Supplier', 'choose_form'=>'Choose Supplier Form', 'choose_master'=>'Choose Supplier Master'],
        'Purchase Order' => ['pending_po'=>'Pending Purchase Order', 'po_form'=>'Create Purchase Order', 'po'=>'Purchase Orders', 'po_items'=>'Purchase Orders Items', 'cancel'=>'Cancel PO Items'],
        'Goods Receipt' => ['pending_grn'=>'Pending Goods Receipt', 'grn_form'=>'Goods Receipt Form', 'grn'=>'Goods Receipt Notes', 'grn_items'=>'Goods Receipt Items', 'grn_slip'=>'Upload GRN Slip'],
        'Invoice' => ['invoice'=>'Upload Invoice', 'match'=>'Link PO Items With Invoice', 'po_invoice_master'=>'PO Item Invoice Master'],
    ];

    // Each screen uses the existing procurement record type and workflow.
    private const PENDING_VIEWS = [
        'pending_indent'=>['indent','approve'],
        'pending_quote'=>['indent','quote'],
        'pending_choose'=>['quote','quote'],
        'pending_po'=>['quote','po'],
        'pending_grn'=>['po','grn'],
    ];

    private const SCREENS = [
        'pending_indent'=>['indent','queue'], 'pending_choose'=>['quote','choose'],
        'boq_form'=>['boq','form'], 'boq'=>['boq','register'], 'boq_items'=>['boq','items'],
        'indent'=>['indent','form'], 'indent_master'=>['indent','register'], 'indent_items'=>['indent','items'],
        'quote_form'=>['quote','form'], 'quote'=>['quote','register'],
        'choose_form'=>['quote','choose'], 'choose_master'=>['quote','selected'],
        'po_form'=>['po','form'], 'po'=>['po','register'], 'po_items'=>['po','items'],
        'grn_form'=>['grn','form'], 'grn'=>['grn','register'], 'grn_items'=>['grn','items'], 'grn_slip'=>['grn','slip'],
    ];

    private static $screen = '';
    private static $exportBuffer = null;

    private const DEFAULT_UNITS = [
        'Kg','Pcs','Bags','Box','Cartons','Packets','Bundles','Cum',
        'Cft','Grm','Roll','Mtrs','PKT','Set','Ltrs','Coil',
    ];

    private const FIELDS = [
        'units'=>['name'=>'Unit'],
        'employee'=>['name'=>'Name', 'email'=>'Email Id', 'mobile'=>'Mobile', 'department'=>'Department', 'login'=>'User ID', 'password'=>'Password'],
        'item'=>['name'=>'Name', 'category'=>'Item Category', 'item_type'=>'Item Type'],
        'category'=>['name'=>'Category name'],
        'item_type'=>['name'=>'Type name'],
        'site'=>['ref'=>'Site Number', 'due'=>'Site Date', 'name'=>'Site Name', 'address'=>'Site Address', 'employee'=>'Site Manager', 'telegram'=>'Telegram Group Id'],
        'supplier'=>['ref'=>'Supplier Code', 'name'=>'Supplier Names', 'contact'=>'Contact Number', 'alternate'=>'Alternate Contact Number', 'email'=>'Email Id', 'address'=>'Address', 'city'=>'City', 'state'=>'State', 'pin'=>'PIN', 'mobile'=>'Mobile', 'gst'=>'GST', 'term'=>'Term'],
        'boq'=>['site'=>'Site Number with Name', 'section'=>'Section', 'name'=>'Item', 'unit'=>'Unit', 'qty'=>'Planned quantity'],
        'indent'=>['boq'=>'BOQ item', 'qty'=>'Required quantity', 'due'=>'Required by'],
        'quote'=>['indent'=>'Approved indent', 'supplier'=>'Supplier Name with Code', 'rate'=>'Unit rate', 'tax'=>'Tax %', 'freight'=>'Freight', 'due'=>'Delivery date', 'note'=>'Payment terms'],
        'po'=>['quote'=>'Selected quotation', 'qty'=>'Order quantity', 'ref'=>'PO number', 'due'=>'Expected delivery'],
        'follow'=>['po'=>'Purchase order', 'note'=>'Follow-up notes', 'due'=>'Next follow-up'],
        'cancel'=>['po'=>'Purchase order', 'qty'=>'Quantity to cancel', 'note'=>'Reason', 'reorder'=>'Return to purchasing'],
        'grn'=>['po'=>'Purchase order', 'ref'=>'GRN number', 'due'=>'Receipt date', 'qty'=>'Accepted quantity', 'reject'=>'Rejected quantity'],
        'invoice'=>['supplier'=>'Supplier Name with Code', 'ref'=>'Invoice number', 'due'=>'Invoice date', 'amount'=>'Invoice Amount'],
        'match'=>['invoice'=>'Supplier invoice', 'grn'=>'Approved GRN', 'qty'=>'Invoice quantity', 'rate'=>'Invoice unit rate', 'note'=>'Variance explanation'],
    ];
    private const PAGE_VERSION = '1.9.55';
    private const PAGE_SLUGS = [
        'settings'=>'settings',
        'pending_indent'=>'pending-approve-indent', 'pending_quote'=>'pending-supplier-quotation',
        'pending_choose'=>'pending-choose-supplier', 'pending_po'=>'pending-purchase-order',
        'pending_grn'=>'pending-goods-receipt',
        'boq_form'=>'create-boq', 'boq_items'=>'boq-item-master',
        'indent_master'=>'indent-master', 'indent_items'=>'indents-item-master',
        'quote_form'=>'supplier-quotation-form', 'choose_form'=>'choose-supplier-form', 'choose_master'=>'choose-supplier-master',
        'po_form'=>'create-purchase-order', 'po_items'=>'purchase-order-items',
        'grn_form'=>'goods-receipt-form', 'grn_items'=>'goods-receipt-items', 'grn_slip'=>'upload-grn-slip',
        'employee'=>'employee-master', 'item'=>'item-master',
        'category'=>'item-categories', 'item_type'=>'item-type', 'supplier'=>'supplier-master', 'units'=>'units',
        'home'=>'frontier', 'site'=>'site-master', 'boq'=>'boq', 'indent'=>'indents',
        'quote'=>'quotation-comparison', 'po'=>'purchase-orders',
        'cancel'=>'cancel-po-items', 'grn'=>'goods-receipt-notes', 'stock'=>'received-stock',
        'invoice'=>'supplier-invoices', 'match'=>'invoice-matching', 'po_invoice_master'=>'po-item-invoice-master',
    ];
    private const EMPLOYEE_ROLES = [
        'frontier_requester'=>'Frontier Requester',
        'frontier_approver'=>'Frontier Indent Approver',
        'frontier_buyer'=>'Frontier Buyer',
        'frontier_receiver'=>'Frontier Receiving',
        'frontier_accounts'=>'Frontier Accounts',
    ];
    private static $front = false;

    private const IMPORT_TEMPLATES = [
        'employee'=>['Name'],
        'item'=>['Name','Item Category','Item Type'],
        'supplier'=>['Supplier Code','Supplier Names','Suppliers Name with Code','Contact Number','Alternate Contact Number','Email Id','Address','City','State','PIN','Mobile','GST','Term'],
    ];

    private const FILES = ['quote'=>'Quotation file', 'grn'=>'Signed GRN slip', 'invoice'=>'Invoice copy'];

    public static function boot() {
        add_action('admin_init', [self::class, 'installPages']);
        add_action('login_enqueue_scripts', [self::class, 'styleFrontierLogin']);
        add_action('template_redirect', [self::class, 'protectPage'], 0);
        add_filter('template_include', [self::class, 'workspaceTemplate'], 99);
        add_filter('render_block_core/navigation-link', [self::class, 'legacyNavigationBlock'], 10, 2);
        add_filter('render_block_core/navigation-submenu', [self::class, 'legacyNavigationBlock'], 10, 2);
        add_filter('wp_list_pages_excludes', [self::class, 'excludeLegacyPages']);
        add_filter('wp_nav_menu_objects', [self::class, 'filterClassicNavigation']);
        add_shortcode('frontier_view', [self::class, 'shortcode']);
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_enqueue_scripts', [self::class, 'adminDropdownSearch']);
        add_action('admin_post_frontier_template', [self::class, 'downloadTemplate']);
        add_action('admin_post_frontier_indent_pdf', [self::class, 'downloadIndentPdf']);
        add_action('admin_post_frontier_boq_print', [self::class, 'printBoq']);
        add_action('admin_post_frontier_po_pdf', [self::class, 'downloadPurchaseOrderPdf']);
        add_action('admin_post_frontier_grn_pdf', [self::class, 'downloadGoodsReceiptPdf']);
        add_action('admin_post_frontier_boq_excel', [self::class, 'boqExcel']);
        add_action('admin_post_frontier_save', [self::class, 'save']);
        add_action('admin_post_frontier_table_excel', [self::class, 'downloadTableExcel']);
        add_action('admin_post_frontier_save_org_name', [self::class, 'saveOrgName']);
        add_action('admin_post_frontier_save_logo', [self::class, 'saveLogo']);
        add_action('admin_post_frontier_save_numbering', [self::class, 'saveNumbering']);
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
        if (!current_user_can('manage_options')) {
            return;
        }
        self::installUnits();
        if (get_option('frontier_page_version') === self::PAGE_VERSION) {
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

    private static function installUnits() {
        global $wpdb;
        if (get_option('frontier_units_seeded')) {
            return;
        }
        $table = self::table();
        $lock = 'frontier_'.md5(DB_NAME.'|'.$table);
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)',$lock)) !== 1) {
            return;
        }
        try {
            if (get_option('frontier_units_seeded')) {
                return;
            }
            $existing = $wpdb->get_col($wpdb->prepare("SELECT details FROM $table WHERE kind = %s",'units'));
            if ($wpdb->last_error) {
                return;
            }
            $names = [];
            foreach ($existing as $details) {
                $data = json_decode($details,true);
                $names[strtolower($data['name'] ?? '')] = true;
            }
            foreach (array_reverse(self::DEFAULT_UNITS) as $unit) {
                if (!isset($names[strtolower($unit)]) && $wpdb->insert($table,[
                    'kind'=>'units','status'=>'recorded','details'=>wp_json_encode(['name'=>$unit]),
                ]) !== 1) {
                    return;
                }
            }
            update_option('frontier_units_seeded',1,false);
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
        }
    }

    private static function requestedFrontView() {
        if (!is_page()) {
            return '';
        }
        $view = (string)get_post_meta(get_queried_object_id(),'_frontier_view',true);
        if ($view === 'home' && !empty($_GET['frontier_view']) && is_string($_GET['frontier_view'])) {
            $target = sanitize_key(wp_unslash($_GET['frontier_view']));
            $frontId = (int)get_option('page_on_front');
            if ($frontId && $target !== 'home' && isset(self::views()[$target])
                && get_option('show_on_front') === 'page'
                && get_post_status($frontId) === 'publish'
                && get_post_meta($frontId,'_frontier_view',true) === $target) {
                return $target;
            }
        }
        return $view;
    }

    public static function styleFrontierLogin() {
        $dashboard = self::frontUrl('home');
        $target = $_POST['redirect_to'] ?? $_GET['redirect_to'] ?? '';
        if (!$dashboard || !is_string($target)) {
            return;
        }
        $destination = wp_parse_url(wp_unslash($target));
        $home = wp_parse_url($dashboard);
        $path = rtrim($home['path'] ?? '','/');
        $targetPath = rtrim($destination['path'] ?? '','/');
        if (empty($home['host']) || ($destination['host'] ?? '') !== $home['host']
            || ($targetPath !== $path && ($path === '' || strpos($targetPath,$path.'/') !== 0))) {
            return;
        }
        wp_enqueue_style('frontier-login',plugins_url('login.css',__FILE__),['login'],'1.9.75');
        add_filter('login_body_class',static function ($classes) {
            $classes[] = 'frontier-login';
            return $classes;
        });
        add_filter('login_headerurl',static function () use ($dashboard) {
            return $dashboard;
        });
        add_filter('login_headertext',static function () {
            return 'Frontier';
        });
        add_filter('login_message',static function ($message) {
            return '<h2 class="frontier-login-title">Log in</h2>'.$message;
        });
        add_filter('gettext',static function ($translation,$original,$domain) {
            return $domain === 'default' && $original === 'Username or Email Address' ? 'User ID' : $translation;
        },10,3);
        $id = (int)get_option('frontier_logo_id',0);
        $logo = $id ? wp_get_attachment_image_url($id,'medium') : '';
        if ($logo) {
            wp_add_inline_style('frontier-login','.frontier-login #login h1 a { background-image: url("'.esc_url_raw($logo).'"); font-size: 0; }');
        }
    }

    public static function protectPage() {
        $kind = self::requestedFrontView();
        if ($kind === 'follow' && ($orders = self::frontUrl('po'))) {
            wp_safe_redirect($orders);
            exit;
        }
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
        wp_enqueue_style('frontier-pages',plugins_url('pages.css',__FILE__),[],'1.9.77');
        self::enqueueDropdownSearch();
    }

    public static function adminDropdownSearch() {
        $page = isset($_GET['page']) && is_string($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page === 'frontier' || strpos($page,'frontier-') === 0) {
            self::enqueueDropdownSearch();
        }
    }

    private static function enqueueDropdownSearch() {
        wp_enqueue_script('frontier-dropdown-search',plugins_url('dropdown-search.js',__FILE__),[],'1.9.70',true);
        wp_enqueue_style('frontier-dropdown-search',plugins_url('dropdown-search.css',__FILE__),[],'1.8.3');
        wp_enqueue_script('frontier-quotation-form',plugins_url('quotation-form.js',__FILE__),['frontier-dropdown-search'],'1.9.70',true);
        wp_enqueue_script('frontier-indent-form',plugins_url('indent-form.js',__FILE__),['frontier-dropdown-search'],'1.9.70',true);
        wp_enqueue_script('frontier-boq-form',plugins_url('boq-form.js',__FILE__),['frontier-dropdown-search'],'1.9.1',true);
        if (is_admin()) {
            wp_enqueue_style('frontier-pages',plugins_url('pages.css',__FILE__),[],'1.9.77');
        }
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
            <?php self::workspaceNavigation($kind); ?>
            <div class="frontier-main-column">
                <header class="frontier-topbar">
                    <div class="frontier-topbar-start">
                        <button type="button" class="frontier-menu-toggle" aria-controls="frontier-sidebar" aria-expanded="true" aria-label="Hide menu" title="Hide menu" hidden><span aria-hidden="true">☰</span></button>
                        <span role="heading" aria-level="1"><?php echo esc_html($views[$kind]); ?></span>
                    </div>
                    <?php
                    $logo = (int)get_option('frontier_logo_id',0);
                    if ($logo) {
                        echo wp_get_attachment_image($logo,'medium',false,[
                            'class'=>'frontier-topbar-logo','alt'=>'Company logo','loading'=>'eager',
                        ]);
                    }
                    ?>
                </header>
                <main id="frontier-main" class="frontier-main">
                    <?php self::render($kind); ?>
                </main>
            </div>
        </div>
        <script>
(() => {
    const shell = document.querySelector('.frontier-shell');
    const menu = document.getElementById('frontier-sidebar');
    const toggle = shell.querySelector('.frontier-menu-toggle');
    const key = 'frontier-sidebar-hidden';
    let preference = null;
    try { preference = localStorage.getItem(key); } catch {}
    const setHidden = hidden => {
        menu.hidden = hidden;
        shell.classList.toggle('frontier-menu-hidden', hidden);
        toggle.setAttribute('aria-expanded', String(!hidden));
        const label = hidden ? 'Show menu' : 'Hide menu';
        toggle.setAttribute('aria-label', label);
        toggle.title = label;
    };
    setHidden(preference === null ? window.matchMedia('(max-width: 760px)').matches : preference === 'true');
    toggle.hidden = false;
    toggle.addEventListener('click', () => {
        setHidden(!menu.hidden);
        try { localStorage.setItem(key, String(menu.hidden)); } catch {}
    });
})();
        </script>
        <?php
    }

    private static function orgNameSettings() {
        if (!current_user_can('manage_options')) {
            return;
        }
        echo '<form class="frontier-entry" method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('frontier_save_org_name');
        echo '<input type="hidden" name="action" value="frontier_save_org_name">';
        echo '<input type="hidden" name="surface" value="'.(self::$front ? 'front' : 'admin').'">';
        echo '<p><label>Org Name<input type="text" name="org_name" value="'.esc_attr(get_option('frontier_org_name','')).'"></label></p>';
        self::submitButton('Save Org Name',true);
        echo '</form>';
    }

    public static function saveOrgName() {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied.','Frontier',['response'=>403]);
        }
        check_admin_referer('frontier_save_org_name');
        update_option('frontier_org_name',self::input('org_name'),false);
        self::$front = self::input('surface') === 'front';
        wp_safe_redirect(add_query_arg('saved',1,self::url('settings')));
        exit;
    }

    private static function logoSettings() {
        $logo = (int)get_option('frontier_logo_id',0);
        if ($logo) {
            echo '<p>'.wp_get_attachment_image($logo,'medium',false,['alt'=>'Current company logo']).'</p>';
        }
        if (!current_user_can('manage_options') || !current_user_can('upload_files')) {
            return;
        }
        echo '<form class="frontier-entry" method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('frontier_save_logo');
        echo '<input type="hidden" name="action" value="frontier_save_logo">';
        echo '<input type="hidden" name="surface" value="'.(self::$front ? 'front' : 'admin').'">';
        echo '<p><label>'.self::requiredLabel('Company Logo').'<input type="file" name="frontier_logo" accept=".png,.jpg,.jpeg" required></label></p>';
        self::submitButton('Save Logo',true);
        echo '</form>';
    }

    private const NUMBER_FIELDS = ['boq'=>'BOQ Number','indent'=>'Indent Number','po'=>'PO Number',
        'grn'=>'GRN Number','supplier'=>'Supplier Code','site'=>'Site Number'];

    private static function fiscalYear($date) {
        return (int)substr($date,0,4) - ((int)substr($date,5,2) < 4 ? 1 : 0);
    }

    private static function poPrefix($year) {
        return sprintf('FY - %02d-%02d/',$year % 100,($year+1) % 100);
    }

    private static function nextNumber($kind,$last,$year=0) {
        $settings = (array)get_option('frontier_next_numbers',[]);
        $key = $kind === 'po' ? 'po_'.$year : $kind;
        return max($last+1,(int)($settings[$key] ?? 1));
    }

    private static function lastNumbers($rows,$year) {
        $last = ['boq'=>(int)get_option('frontier_last_deleted_boq',0),'indent'=>0,
            'po'=>(int)get_option('frontier_po_sequence_'.$year,0),'grn'=>0,
            'supplier'=>self::lastMasterCode($rows,'supplier'),'site'=>self::lastMasterCode($rows,'site')];
        $prefix = self::poPrefix($year);
        foreach ($rows as $row) {
            $kind = $row['kind'];
            $data = $row['data'];
            if ($kind === 'boq' || $kind === 'indent') {
                $last[$kind] = max($last[$kind],(int)($data[$kind.'_number'] ?? 0));
            } elseif ($kind === 'grn') {
                $last['grn'] = max($last['grn'],(int)($data['grn_number'] ?? self::goodsReceiptNumberId($row)));
            } elseif ($kind === 'po' && strpos($data['ref'] ?? '',$prefix) === 0) {
                $last['po'] = max($last['po'],(int)substr($data['ref'],strlen($prefix)));
            }
        }
        return $last;
    }

    private static function numberingPreview($kind,$number,$year) {
        if ($kind === 'po' || $kind === 'grn') {
            return ($kind === 'po' ? self::poPrefix($year) : 'GRN - ').str_pad((string)$number,4,'0',STR_PAD_LEFT);
        }
        return in_array($kind,['supplier','site'],true) ? self::formatMasterCode($number) : (string)$number;
    }

    private static function numberingSettings() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $current = self::fiscalYear(current_time('Y-m-d'));
        $year = absint($_GET['number_year'] ?? $current);
        $year = $year >= 1900 && $year <= 9998 ? $year : $current;
        $url = self::url('settings');
        $query = [];
        parse_str((string)wp_parse_url($url,PHP_URL_QUERY),$query);
        echo '<section class="frontier-entry"><h2>Document Numbering</h2>';
        echo '<form method="get" action="'.esc_url(remove_query_arg(array_keys($query),$url)).'">';
        foreach ($query as $key=>$value) {
            echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr($value).'">';
        }
        echo '<p><label>PO Financial Year Start <input type="number" name="number_year" min="1900" max="9998" required value="'.esc_attr($year).'"></label></p>';
        self::submitButton('Show financial year');
        echo '</form><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('frontier_save_numbering');
        echo '<input type="hidden" name="action" value="frontier_save_numbering">';
        echo '<input type="hidden" name="number_year" value="'.esc_attr($year).'">';
        echo '<input type="hidden" name="surface" value="'.(self::$front ? 'front' : 'admin').'">';
        echo '<div class="frontier-table"><table class="widefat striped"><thead><tr><th>Field</th><th>Last used</th><th>Next number</th><th>Format preview</th></tr></thead><tbody>';
        $last = self::lastNumbers(self::rows(),$year);
        foreach (self::NUMBER_FIELDS as $kind=>$label) {
            $next = self::nextNumber($kind,$last[$kind],$year);
            echo '<tr><th scope="row">'.esc_html('Next '.$label).'</th><td>';
            echo $last[$kind] ? esc_html(self::numberingPreview($kind,$last[$kind],$year)) : '';
            echo '</td><td><input type="number" name="next_'.esc_attr($kind).'" aria-label="'.esc_attr('Next '.$label).'" required min="'.esc_attr($last[$kind]+1).'" step="1" value="'.esc_attr($next).'"></td>';
            echo '<td>'.esc_html(self::numberingPreview($kind,$next,$year)).'</td></tr>';
        }
        echo '</tbody></table></div><p>';
        self::submitButton('Save Numbering',true);
        echo '</p></form></section>';
    }

    public static function saveNumbering() {
        global $wpdb;
        if (!current_user_can('manage_options')) {
            wp_die('Access denied.','Frontier',['response'=>403]);
        }
        check_admin_referer('frontier_save_numbering');
        $year = absint(self::input('number_year'));
        if ($year < 1900 || $year > 9998) {
            wp_die('Enter a valid financial year.','Frontier',['response'=>400,'back_link'=>true]);
        }
        $lock = 'frontier_'.md5(DB_NAME.'|'.self::table());
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)',$lock)) !== 1) {
            wp_die('Frontier is busy. Please retry.','Frontier',['response'=>503]);
        }
        $error = '';
        try {
            $last = self::lastNumbers(self::rows(),$year);
            $previous = $settings = (array)get_option('frontier_next_numbers',[]);
            foreach (self::NUMBER_FIELDS as $kind=>$label) {
                $next = self::input('next_'.$kind);
                self::requireRule(preg_match('/^[1-9]\d{0,8}$/D',$next) && (int)$next > $last[$kind],
                    'Next '.$label.' must be greater than the last number already used.');
                $settings[$kind === 'po' ? 'po_'.$year : $kind] = (int)$next;
            }
            if ($previous !== $settings && !update_option('frontier_next_numbers',$settings,false)) {
                throw new RuntimeException('Numbering settings could not be saved.');
            }
        } catch (Throwable $exception) {
            $error = $exception instanceof DomainException ? $exception->getMessage() : 'Numbering settings could not be saved.';
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
        }
        if ($error !== '') {
            wp_die(esc_html($error),'Frontier',['response'=>400,'back_link'=>true]);
        }
        self::$front = self::input('surface') === 'front';
        wp_safe_redirect(add_query_arg(['saved'=>1,'number_year'=>$year],self::url('settings')));
        exit;
    }

    public static function saveLogo() {
        if (!current_user_can('manage_options') || !current_user_can('upload_files')) {
            wp_die('Access denied.','Frontier',['response'=>403]);
        }
        check_admin_referer('frontier_save_logo');
        $file = $_FILES['frontier_logo'] ?? [];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['size']) || $file['size'] > 3*1024*1024) {
            wp_die('Choose a PNG or JPEG logo up to 3 MB.','Frontier',['response'=>400,'back_link'=>true]);
        }
        require_once ABSPATH.'wp-admin/includes/image.php';
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/media.php';
        $logo = media_handle_upload('frontier_logo',0,[],['test_form'=>false,'mimes'=>['png'=>'image/png','jpg|jpeg'=>'image/jpeg']]);
        if (is_wp_error($logo)) {
            wp_die(esc_html($logo->get_error_message()),'Frontier',['response'=>400,'back_link'=>true]);
        }
        update_option('frontier_logo_id',(int)$logo,false);
        self::$front = self::input('surface') === 'front';
        wp_safe_redirect(add_query_arg('saved',1,self::url('settings')));
        exit;
    }

    private static function workspaceNavigation($current) {
        echo '<nav id="frontier-sidebar" class="frontier-sidebar" aria-label="Frontier navigation">';
        $orgName = trim((string)get_option('frontier_org_name',''));
        $brand = $orgName === '' ? 'Frontier' : $orgName;
        echo '<a class="frontier-sidebar-brand" href="'.esc_url(self::url('home')).'">'.esc_html($brand).'</a>';
        self::workspaceNavigationLink('home','Dashboard',$current);
        foreach (self::GROUPS as $group=>$views) {
            $visible = array_filter($views,[self::class,'canView'],ARRAY_FILTER_USE_KEY);
            if (!$visible) {
                continue;
            }
            echo '<details'.(isset($visible[$current]) ? ' open' : '').'><summary>'.esc_html($group).'</summary><ul>';
            foreach ($visible as $view=>$label) {
                echo '<li>';
                self::workspaceNavigationLink($view,$label,$current);
                echo '</li>';
            }
            echo '</ul></details>';
        }
        echo '<a class="frontier-sidebar-signout" href="'.esc_url(wp_logout_url(self::frontUrl('home') ?: home_url('/'))).'">Sign out</a>';
        echo '</nav>';
    }

    private static function workspaceNavigationLink($view,$label,$current) {
        $active = $view === $current ? ' aria-current="page"' : '';
        echo '<a href="'.esc_url(self::url($view)).'"'.$active.'>'.esc_html($label).'</a>';
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

    private static function assignedViewAccess($userId) {
        return $userId ? get_user_meta($userId,'frontier_view_access',true) : [];
    }

    private static function canView($kind) {
        $user = wp_get_current_user();
        if (!user_can($user,'manage_options') && user_can($user,'frontier_read')) {
            $access = self::assignedViewAccess($user->ID);
            if (is_array($access) && isset($access['role'])) {
                if (($access['status'] ?? '') === 'inactive') {
                    return false;
                }
                return isset(self::views()[$kind]) && ($kind === 'home' || $access['role'] === 'admin'
                    || in_array($kind,$access['views'] ?? [],true));
            }
        }
        return self::defaultViewAccess($kind,$user);
    }

    private static function defaultViewAccess($kind,$user) {
        if (user_can($user,'manage_options')) {
            return true;
        }
        if (!user_can($user,'frontier_read') || $kind === 'settings' || isset(self::MASTERS[$kind])) {
            return false;
        }
        $capability = self::PENDING_VIEWS[$kind][1] ?? ['choose_form'=>'quote','grn_slip'=>'grn'][$kind] ?? '';
        if ($capability !== '') {
            return user_can($user,'frontier_'.$capability);
        }
        return !in_array($kind,['invoice','match','po_invoice_master'],true)
            || user_can($user,'frontier_invoice') || user_can($user,'frontier_match');
    }

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'frontier_records';
    }

    private static function can($capability) {
        if (current_user_can('manage_options')) {
            return true;
        }
        $access = self::assignedViewAccess(get_current_user_id());
        return ($access['status'] ?? '') !== 'inactive' && current_user_can('frontier_'.$capability);
    }

    public static function menu() {
        $cap = current_user_can('manage_options') ? 'manage_options' : 'frontier_read';
        add_menu_page('Frontier','Frontier',$cap,'frontier',[self::class,'page'],'dashicons-clipboard');
        foreach (self::views() as $kind=>$label) {
            if ($kind === 'stock' || !self::canView($kind)) {
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

    private static function canReceivePurchaseOrder($rows,$po) {
        return in_array($po['status'],['draft','issued'],true) && self::balance($rows,$po) > 0;
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

    private static function siteNumberWithName($data) {
        $code = $data['ref'] ?? '';
        $name = $data['name'] ?? '';
        return $code !== '' && $name !== '' ? $code.' - '.$name : ($name !== '' ? $name : $code);
    }

    private static function supplierDisplay($data,$rows) {
        $master = $rows[$data['supplier'] ?? 0] ?? null;
        if (!$master || $master['kind'] !== 'supplier') {
            $name = trim($data['name'] ?? '');
            if ($name === '') {
                return '';
            }
            $master = null;
            foreach ($rows as $candidate) {
                if ($candidate['kind'] === 'supplier' && strcasecmp($candidate['data']['name'],$name) === 0) {
                    if ($master) {
                        return $name;
                    }
                    $master = $candidate;
                }
            }
            if (!$master) {
                return $name;
            }
        }
        $code = $master['data']['ref'] ?? '';
        return ($code !== '' ? $code.' - ' : '').$master['data']['name'];
    }

    private static function sameSupplier($first,$second) {
        return !empty($first['supplier']) && !empty($second['supplier'])
            ? (int)$first['supplier'] === (int)$second['supplier']
            : strcasecmp($first['name'] ?? '',$second['name'] ?? '') === 0;
    }

    private static function label($row,$rows) {
        $data = $row['data'];
        if ($row['kind'] === 'site') {
            return self::siteNumberWithName($data);
        }
        if ($row['kind'] === 'supplier') {
            return self::supplierDisplay(['supplier'=>$row['id']],$rows);
        }
        if (in_array($row['kind'],['quote','po','invoice'],true)) {
            $data['name'] = self::supplierDisplay($data,$rows);
        }
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
                case 'po':
                    return $kind === 'grn' ? self::canReceivePurchaseOrder($rows,$row)
                        : $row['status'] === 'issued' && ($kind === 'follow' || self::balance($rows,$row) > 0);
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
            $counts = self::dashboardCounts(self::rows());
            foreach (self::GROUPS as $group=>$views) {
                $visible = array_filter(array_keys($views),[self::class,'canView']);
                if (!$visible) {
                    continue;
                }
                echo '<section class="frontier-card-'.esc_attr(sanitize_title($group)).'"><h2>'.esc_html($group).'</h2><ul>';
                foreach ($visible as $key) {
                    echo '<li><a class="frontier-dashboard-link" href="'.esc_url(self::url($key)).'"><span>'.esc_html($views[$key]).'</span>';
                    if (isset($counts[$key])) {
                        echo '<span class="frontier-dashboard-count">'.esc_html(number_format_i18n($counts[$key])).'</span>';
                    }
                    echo '</a></li>';
                }
                echo '</ul></section>';
            }
            echo self::$front ? '</div></div>' : '</div>';
            return;
        }
        if (!self::$front) {
            echo '<p><a href="'.esc_url(self::url('home')).'">Back to Dashboard</a></p>';
        }
        if (isset($_GET['saved']) || isset($_GET['deleted'])) {
            $message = isset($_GET['deleted']) ? 'Record deleted.' : 'Saved.';
            echo '<div class="notice notice-success" role="status"><p>'.esc_html($message).'</p></div>';
        }
        self::$screen = $kind;
        if ($kind === 'settings') {
            self::orgNameSettings();
            self::logoSettings();
            self::numberingSettings();
            echo '</div>';
            return;
        }
        [$kind,$mode] = self::SCREENS[$kind] ?? [$kind,'combined'];
        $rows = self::rows();
        if (isset(self::PENDING_VIEWS[self::$screen])) {
            self::pendingWork($rows,self::$screen);
            echo '</div>';
            return;
        }
        if (self::$screen === 'quote_form' && !isset($_GET['single_quote'])) {
            if (self::can('quote')) {
                self::quotationForm($rows);
            }
            echo '</div>';
            return;
        }
        if (in_array(self::$screen,['match','po_invoice_master'],true)) {
            self::purchaseInvoiceView($rows,self::$screen === 'po_invoice_master');
            echo '</div>';
            return;
        }
        if (self::$screen === 'grn_form') {
            if (self::can('grn')) {
                self::goodsReceiptForm($rows);
            }
            echo '</div>';
            return;
        }
        if (self::$screen === 'po_form') {
            if (self::can('po')) {
                self::purchaseOrderForm($rows);
            }
            echo '</div>';
            return;
        }
        if (self::$screen === 'choose_form') {
            self::chooseSupplierForm($rows);
            echo '</div>';
            return;
        }
        if (self::$screen === 'indent_master') {
            if (self::can('indent')) {
                echo '<p><a class="button frontier-add" href="'.esc_url(self::url('indent')).'">Add</a></p>';
            }
            self::indentRegister($rows);
            echo '</div>';
            return;
        }
        if ($kind === 'boq') {
            $editId = absint($_GET['edit'] ?? 0);
            if ($mode === 'form' || ($mode === 'register' && $editId)) {
                if (self::can('boq')) {
                    if ($editId && (!isset($rows[$editId]) || $rows[$editId]['kind'] !== 'boq')) {
                        wp_die('BOQ not found.','Frontier',['response'=>404]);
                    }
                    $lines = $editId ? self::boqLines($rows,$editId) : [];
                    self::boqForm($rows,$lines);
                } else {
                    echo '<p>You do not have permission to create a BOQ.</p>';
                }
            } else {
                self::boqRegister($rows,$mode === 'items');
            }
            echo '</div>';
            return;
        }
        if ($kind === 'site' && isset($_GET['site_action']) && self::can('sites')) {
            $action = $_GET['site_action'];
            $site = $rows[absint($_GET['site_id'] ?? 0)] ?? null;
            if (in_array($action,['complete_site','cancel_site'],true) && $site && $site['kind'] === 'site'
                && !in_array($site['status'],['completed','cancelled'],true)) {
                self::siteStatusForm($site,$action);
                echo '</div>';
                return;
            }
        }
        $edit = $rows[absint($_GET['edit'] ?? 0)] ?? null;
        if (self::$screen === 'indent' && !isset($_GET['edit'])) {
            if (self::can('indent')) {
                self::indentForm($rows);
            }
            echo '</div>';
            return;
        }
        $editing = $edit && $edit['kind'] === $kind && ((isset(self::MASTERS[$kind]) || $kind === 'site')
            || ($kind === 'indent' && in_array($edit['status'],['draft','returned','approved'],true)));
        if (isset(self::IMPORT_TEMPLATES[$kind]) && isset($_GET['upload']) && self::can($kind)) {
            self::uploadTemplateForm($kind);
            echo '</div>';
            return;
        }
        if ($kind === 'stock') {
            self::stock($rows);
            echo '</div>';
            return;
        }
        $canAdd = in_array($mode,['form','combined','register'],true) && self::can($kind === 'site' ? 'sites' : $kind);
        $showForm = $canAdd && $mode !== 'register' && ($editing || isset($_GET['add']));
        if ($canAdd && !$showForm && self::$screen !== 'quote') {
            $formScreen = $mode === 'register' ? ([
                'boq'=>'boq_form','indent'=>'indent','quote'=>'quote_form','po'=>'po_form','grn'=>'grn_form',
            ][$kind] ?? self::$screen) : self::$screen;
            $addUrl = add_query_arg('add',1,self::url($formScreen));
            if (isset(self::IMPORT_TEMPLATES[$kind])) {
                self::templateControls($kind,$addUrl);
            } else {
                echo '<p><a class="button frontier-add" href="'.esc_url($addUrl).'">Add</a>';
                echo '</p>';
            }
        }
        if ($showForm) {
            self::formStart($kind,$editing ? 'edit' : 'create',$editing ? $edit['id'] : 0);
            if (self::$front) {
                echo '<div class="frontier-fields">';
            }
            foreach (self::FIELDS[$kind] as $field=>$label) {
                if ($field === 'ref' && in_array($kind,['site','supplier'],true)) {
                    continue;
                }
                $value = $editing ? ($edit['data'][$field] ?? '') : '';
                $prefill = ['quote_form'=>'indent','po_form'=>'quote','grn_form'=>'po','cancel'=>'po'][self::$screen] ?? '';
                if (!$editing && $field === $prefill) {
                    $value = absint($_GET['source'] ?? 0);
                }
                self::field($rows,$kind,$field,$label,$value,$editing && !empty($edit['data']['wp_user_id']));
            }
            if ($kind === 'employee') {
                self::employeeViewAccessFields($editing ? $edit['data'] : []);
            }
            if (isset(self::FILES[$kind])) {
                self::uploadField($kind,$kind === 'invoice');
            }
            if (self::$front) {
                echo '</div>';
            }
            if ($kind === 'employee') {
                self::employeeViewAccessGroups($editing ? $edit['data'] : []);
            }
            self::submitButton($editing ? 'Save changes' : ($kind === 'indent' ? 'Save draft' : 'Save'),true);
            echo ' <a class="button" href="'.esc_url(self::url(self::$screen)).'">Cancel</a></form></div>';
            return;
        }
        if ($mode === 'form') {
            $register = $kind === 'indent' ? 'indent_master' : $kind;
            echo '<p><a href="'.esc_url(self::url($register)).'">'.esc_html($tabs[$register]).'</a></p>';
        }
        $receiptsWithSlips = [];
        if (self::$screen === 'grn_slip') {
            foreach ($rows as $receipt) {
                if ($receipt['kind'] === 'grn' && !empty($receipt['filename'])) {
                    $receiptsWithSlips[self::goodsReceiptNumberId($receipt)] = true;
                }
            }
        }
        $listing = [];
        $indentGroup = self::indentGroupFilter();
        $indentFilter = absint($_GET['indent'] ?? 0);
        foreach ($rows as $row) {
            if ($row['kind'] !== $kind && !($kind === 'follow' && $row['kind'] === 'po' && $row['status'] === 'issued' && self::balance($rows,$row) > 0)) {
                continue;
            }
            if ($row['kind'] === 'invoice' && !self::can('invoice') && !self::can('match')) {
                continue;
            }
            if ($kind === 'quote' && $indentFilter && (int)$row['data']['indent'] !== $indentFilter) {
                continue;
            }
            if (($mode === 'choose' && $row['status'] === 'selected')
                || ($mode === 'selected' && $row['status'] !== 'selected')) {
                continue;
            }
            if ($indentGroup !== '' && self::indentGroup($row) !== $indentGroup) {
                continue;
            }
            if (self::$screen === 'po' && !empty($row['data']['po_group_id']) && (int)$row['id'] !== (int)$row['data']['po_group_id']) {
                continue;
            }
            if (in_array(self::$screen,['grn','grn_slip'],true)) {
                $receiptId = self::goodsReceiptNumberId($row);
                if ($receiptId !== (int)$row['id'] || (self::$screen === 'grn_slip' && isset($receiptsWithSlips[$receiptId]))) {
                    continue;
                }
            }
            $listing[] = $row;
        }
        if (self::$screen === 'quote') {
            self::quotationRegister($listing,$rows);
        } else {
            self::renderRecords($listing,$rows,$kind,$indentGroup !== '' ? 'register' : $mode);
        }
        echo '</div>';
    }

    private static function quotationRegister($listing,$rows) {
        $grouped = [];
        foreach ($listing as $quote) {
            $grouped[(int)($quote['data']['indent'] ?? 0)][] = $quote;
        }

        $entries = [];
        foreach ($grouped as $quotes) {
            foreach (array_chunk($quotes,3) as $comparison) {
                $entries[] = $comparison;
            }
        }
        $columns = ['Indent No. with Site Name','Timestamp','Date','Prepared By','Sl','Item Name','Qty',
            'Supplier1','Rate1','Supplier2','Rate2','Supplier3','Rate3','Site Name'];

        $quoteValues = static function ($quotes) use ($rows) {
            $latest = $quotes[0]['data'];
            foreach ($quotes as $quote) {
                if (($quote['data']['quote_timestamp'] ?? '') > ($latest['quote_timestamp'] ?? '')) {
                    $latest = $quote['data'];
                }
            }
            $indentId = (int)($latest['indent'] ?? 0);
            $indent = $rows[$indentId]['data'] ?? [];
            $boq = $rows[$indent['boq'] ?? 0]['data'] ?? [];
            $site = $rows[$indent['site'] ?? ($boq['site'] ?? 0)]['data'] ?? [];
            $number = $indent['indent_number'] ?? ($indentId ? '#'.$indentId : '');
            $siteLabel = self::siteNumberWithName($site);
            $indentLabel = implode(' · ',array_filter([(string)$number,$siteLabel],'strlen'));
            $timestamp = $latest['quote_timestamp'] ?? '';
            $values = [$indentLabel,$timestamp ? mysql2date('d/m/Y H:i:s',$timestamp,false) : '',
                self::indentDisplayDate($latest['quote_date'] ?? ''),$latest['comparison_name'] ?? '',
                $indent ? ($indent['line_number'] ?? 1) : '',$boq['name'] ?? '',$indent['qty'] ?? ''];

            return [$values,$site['name'] ?? ''];
        };
        [$pageRows,$page,$pages,$total] = self::pagedRows($entries,$columns,static function ($quotes) use ($quoteValues,$rows) {
            [$cells,$site] = $quoteValues($quotes);
            for ($slot=0;$slot<3;$slot++) {
                $quote = $quotes[$slot] ?? [];
                $data = $quote['data'] ?? [];
                $name = self::supplierDisplay($data,$rows);
                if (!empty($quote['filename']) && self::canFile('quote')) {
                    $name = self::exportLink($name.' '.$quote['filename'],'frontier_file',$quote['id']);
                }
                $cells[] = $name;
                $cells[] = $data['rate'] ?? '';
            }
            $cells[] = $site;
            return $cells;
        });
        echo '<div class="frontier-table frontier-master-table" tabindex="0" role="region" aria-label="Supplier Quotation Master"><table class="widefat striped"><thead><tr>';
        foreach ($columns as $label) {
            echo '<th scope="col">'.esc_html($label).'</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($pageRows as $quotes) {
            [$values,$siteName] = $quoteValues($quotes);
            echo '<tr>';
            foreach ($values as $value) {
                echo '<td>'.esc_html((string)$value).'</td>';
            }
            for ($slot = 0; $slot < 3; $slot++) {
                $quote = $quotes[$slot] ?? null;
                $data = $quote['data'] ?? [];
                echo '<td>'.esc_html(self::supplierDisplay($data,$rows));
                if (!empty($quote['filename']) && self::canFile('quote')) {
                    $url = add_query_arg(['action'=>'frontier_file','id'=>$quote['id']],admin_url('admin-post.php'));
                    echo '<br><a href="'.esc_url(wp_nonce_url($url,'frontier_file_'.$quote['id'])).'">'.esc_html($quote['filename']).'</a>';
                }
                echo '</td><td>'.esc_html((string)($data['rate'] ?? '')).'</td>';
            }
            echo '<td>'.esc_html($siteName).'</td></tr>';
        }
        if (!$total) {
            echo '<tr><td colspan="'.count($columns).'">No supplier quotations found.</td></tr>';
        }
        echo '</tbody></table></div>';
        self::pagination($page,$pages,$total);
    }

    private static function dashboardCounts($rows) {
        $direct = self::MASTERS + ['site'=>'','cancel'=>'','invoice'=>'',
            'po_items'=>'','grn_items'=>'','indent_items'=>'','boq_items'=>'','match'=>'','po_invoice_master'=>''];
        $counts = array_fill_keys(array_merge(array_keys($direct),
            ['boq','indent_master','quote','choose_master','po','grn','grn_slip'],array_keys(self::PENDING_VIEWS)),0);
        $viewsByKind = ['boq'=>'boq_items','indent'=>'indent_items','po'=>'po_items','grn'=>'grn_items','match'=>'po_invoice_master'];
        $boqs = $indents = $pendingIndents = $quotes = $receipts = $slips = [];
        foreach ($rows as $row) {
            $kind = $row['kind'];
            $key = $viewsByKind[$kind] ?? $kind;
            if (isset($direct[$key])) {
                $counts[$key]++;
            }
            switch ($kind) {
                case 'boq':
                    $boqs[self::boqGroup($row)] = true;
                    break;
                case 'indent':
                    $group = self::indentGroup($row);
                    $indents[$group] = true;
                    if ($row['status'] === 'pending') {
                        $pendingIndents[$group] = true;
                    }
                    break;
                case 'quote':
                    $id = (int)($row['data']['indent'] ?? 0);
                    $quotes[$id] = ($quotes[$id] ?? 0) + 1;
                    if ($row['status'] === 'selected') {
                        $counts['choose_master']++;
                    }
                    break;
                case 'po':
                    $counts['match']++;
                    if ((int)($row['data']['po_group_id'] ?? $row['id']) === (int)$row['id']) {
                        $counts['po']++;
                    }
                    break;
                case 'grn':
                    $id = self::goodsReceiptNumberId($row);
                    $receipts[$id] = true;
                    if (!empty($row['filename'])) {
                        $slips[$id] = true;
                    }
                    break;
            }
        }
        $counts['boq'] = count($boqs);
        $counts['indent_master'] = count($indents);
        $counts['pending_indent'] = count($pendingIndents);
        $counts['grn'] = count($receipts);
        $counts['grn_slip'] = count(array_diff_key($receipts,$slips));
        foreach ($quotes as $quantity) {
            $counts['quote'] += (int)ceil($quantity / 3);
        }
        foreach (array_keys(self::PENDING_VIEWS) as $screen) {
            if ($screen !== 'pending_indent' && self::canView($screen)) {
                $counts[$screen] = count(self::pendingRows($rows,$screen)[0]);
            }
        }
        return $counts;
    }

    private static function pendingWork($rows,$screen) {
        if ($screen === 'pending_indent') {
            self::indentRegister($rows,true);
            return;
        }
        [$listing,$choiceItems] = self::pendingRows($rows,$screen);
        self::renderRecords($listing,$rows,self::PENDING_VIEWS[$screen][0],'queue',$choiceItems);
    }

    private static function pendingRows($rows,$screen) {
        $listing = [];
        $quoted = [];
        $selected = [];
        foreach ($rows as $row) {
            if ($row['kind'] === 'quote') {
                $indent = (int)$row['data']['indent'];
                $quoted[$indent] = true;
                if ($row['status'] === 'selected') {
                    $previous = $selected[$indent] ?? null;
                    if (!$previous || ($row['data']['compared_at'] ?? '') > ($previous['data']['compared_at'] ?? '')) {
                        $selected[$indent] = $row;
                    }
                }
            }
        }
        $choiceItems = $screen === 'pending_choose' ? self::supplierChoices($rows) : [];
        $listedIndents = [];
        [$kind] = self::PENDING_VIEWS[$screen];
        foreach ($rows as $row) {
            if ($row['kind'] !== $kind) {
                continue;
            }
            $id = (int)$row['id'];
            $indentId = (int)($row['data']['indent'] ?? 0);
            $indent = $rows[$indentId] ?? null;
            switch ($screen) {
                case 'pending_quote':
                    $pending = $row['status'] === 'approved' && !isset($quoted[$id]);
                    break;
                case 'pending_choose':
                    $pending = isset($choiceItems[$indentId]) && !isset($listedIndents[$indentId]);
                    if ($pending) {
                        $listedIndents[$indentId] = true;
                    }
                    break;
                case 'pending_po':
                    $pending = ($indent['status'] ?? '') === 'approved'
                        && (int)($selected[$indentId]['id'] ?? 0) === $id && self::available($rows,$indent) > 0;
                    break;
                case 'pending_grn':
                    $pending = self::canReceivePurchaseOrder($rows,$row);
                    break;
            }
            if ($pending) {
                $listing[] = $row;
            }
        }
        return [$listing,$choiceItems];
    }

    public static function downloadTableExcel() {
        $view = sanitize_key(self::tableQuery('view'));
        if (!isset(self::views()[$view]) || !self::canView($view)) {
            wp_die('Access denied.','Frontier',['response'=>403]);
        }
        check_admin_referer('frontier_table_excel_'.$view);
        self::$front = self::tableQuery('surface') === 'front';
        self::$exportBuffer = ob_get_level();
        ob_start();
        self::render($view);
        ob_end_clean();
        wp_die('This view has no table to export.','Frontier',['response'=>404]);
    }

    private static function sendTableExcel($columns,$rows,$values,$cache) {
        while (ob_get_level() > self::$exportBuffer) {
            ob_end_clean();
        }
        if (!class_exists('ZipArchive')) {
            wp_die('Excel export requires PHP ZipArchive.','Frontier',['response'=>503]);
        }
        $records = [];
        foreach ($rows as $id=>$row) {
            $records[] = $cache[$id] ?? $values($row,$id);
        }
        $path = tempnam(sys_get_temp_dir(),'frontier-export-');
        try {
            if (!$path || !Frontier_Boq_Workbook::export($path,$columns,$records)) {
                if ($path) {
                    unlink($path);
                }
                wp_die('Could not create the Excel workbook.','Frontier',['response'=>500]);
            }
            $name = sanitize_file_name(str_replace(' ','-',self::views()[self::$screen]).'-'.current_time('Y-m-d').'.xlsx');
            nocache_headers();
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="'.$name.'"');
            header('Content-Length: '.filesize($path));
            readfile($path);
        } finally {
            if ($path) {
                unlink($path);
            }
        }
        exit;
    }

    private static function exportLink($text,$action,$id) {
        $url = add_query_arg(['action'=>$action,'id'=>$id],admin_url('admin-post.php'));
        return ['text'=>$text,'url'=>html_entity_decode(wp_nonce_url($url,$action.'_'.$id),ENT_QUOTES,'UTF-8')];
    }

    private static function tableQuery($key) {
        return is_string($_GET[$key] ?? null) ? sanitize_text_field(wp_unslash($_GET[$key])) : '';
    }

    private static function tableSearchUrl() {
        $url = self::url(self::$screen);
        if ($indent = absint($_GET['indent'] ?? 0)) {
            $url = add_query_arg('indent',$indent,$url);
        }
        if ($group = self::indentGroupFilter()) {
            $url = add_query_arg('indent_group',$group,$url);
        }
        return $url;
    }

    private static function pagedRows($rows,$columns,$values) {
        $search = self::tableQuery('frontier_search');
        $dimensions = [];
        if (!isset(self::GROUPS['Master'][self::$screen])) {
            $names = array_map('strtolower',$columns);
            $labels = [
                'date'=>['boq date','indent date','quotation date','selection date','po date','grn date','invoice date','receipt date','site date','date','required by','target date','expected delivery'],
                'site'=>['site number with name','site name','site'],
                'item'=>['item name','item','boq item'],
            ];
            foreach ($labels as $dimension=>$alternatives) {
                foreach ($alternatives as $label) {
                    $key = array_search($label,$names,true);
                    if ($key !== false) {
                        $dimensions[$dimension] = $key;
                        break;
                    }
                }
            }
        }
        [$from,$to,$site,$item] = array_map([self::class,'tableQuery'],['boq_from','boq_to','boq_site','boq_item']);
        if (!isset($dimensions['date'])) {
            $from = $to = '';
        }
        $site = isset($dimensions['site']) ? $site : '';
        $item = isset($dimensions['item']) ? $item : '';
        $hasFacets = $from !== '' || $to !== '' || $site !== '' || $item !== '';
        $cellCache = [];
        $options = ['site'=>[],'item'=>[]];
        if (self::$exportBuffer === null && (isset($dimensions['site']) || isset($dimensions['item']))) {
            foreach ($rows as $id=>$row) {
                $cells = $values($row,$id);
                if ($search !== '' || $hasFacets) {
                    $cellCache[$id] = $cells;
                }
                foreach (['site','item'] as $dimension) {
                    $value = isset($dimensions[$dimension]) ? ($cells[$dimensions[$dimension]] ?? '') : '';
                    if (is_scalar($value) && (string)$value !== '') {
                        $options[$dimension][(string)$value] = (string)$value;
                    }
                }
            }
        }
        if (self::$exportBuffer === null) {
            $url = self::tableSearchUrl();
            $query = [];
            parse_str((string)wp_parse_url($url,PHP_URL_QUERY),$query);
            echo '<form method="get" role="search" aria-label="Search table" action="'.esc_url(remove_query_arg(array_keys($query),$url)).'">';
            foreach ($query as $name=>$value) {
                echo '<input type="hidden" name="'.esc_attr($name).'" value="'.esc_attr($value).'">';
            }
            echo '<div style="display:flex;align-items:end;gap:8px;flex-wrap:wrap">';
            echo '<label>Search <input type="search" name="frontier_search" value="'.esc_attr($search).'"'.($search !== '' ? ' autofocus' : '').'></label>';
            if ($dimensions) {
                echo '<button class="button" type="button" data-table-filter-toggle aria-controls="frontier-table-filters" aria-expanded="'.($hasFacets ? 'true' : 'false').'">Filter</button>';
            }
            if ($search !== '' || $hasFacets) {
                echo '<a class="button" href="'.esc_url($url).'">Clear all</a>';
            }
            $exportArgs = ['action'=>'frontier_table_excel','view'=>self::$screen,'surface'=>self::$front ? 'front' : 'admin'];
            foreach (['frontier_search','boq_from','boq_to','boq_site','boq_item','indent'] as $key) {
                if (($value = self::tableQuery($key)) !== '') {
                    $exportArgs[$key] = $value;
                }
            }
            if ($group = self::indentGroupFilter()) {
                $exportArgs['indent_group'] = $group;
            }
            $exportUrl = wp_nonce_url(add_query_arg($exportArgs,admin_url('admin-post.php')),'frontier_table_excel_'.self::$screen);
            echo '<a class="button frontier-download" href="'.esc_url($exportUrl).'">Download Excel</a>';
            echo '</div>';
            if ($dimensions) {
                echo '<div id="frontier-table-filters"'.($hasFacets ? '' : ' hidden').'><div style="display:flex;align-items:end;gap:8px;flex-wrap:wrap;margin-top:8px">';
                if (isset($dimensions['date'])) {
                    echo '<label>Date From <input type="date" name="boq_from" value="'.esc_attr($from).'"></label>';
                    echo '<label>Date To <input type="date" name="boq_to" value="'.esc_attr($to).'"></label>';
                }
                foreach (['site'=>['Site',$site],'item'=>['Item',$item]] as $key=>[$label,$active]) {
                    if (!isset($dimensions[$key])) {
                        continue;
                    }
                    natcasesort($options[$key]);
                    echo '<label>'.esc_html($label).' <select name="boq_'.esc_attr($key).'"><option value="">All</option>';
                    foreach ($options[$key] as $option) {
                        echo '<option value="'.esc_attr($option).'" '.selected($active,$option,false).'>'.esc_html($option).'</option>';
                    }
                    echo '</select></label>';
                }
                echo '<button class="button" type="submit">Apply filter</button></div></div>';
            }
            echo '</form>';
        echo <<<'HTML'
<script>
(() => {
    const form = document.currentScript.previousElementSibling;
    const search = form.elements.frontier_search;
    const initial = search.value;
    const toggle = form.querySelector('[data-table-filter-toggle]');
    if (toggle) {
        const panel = document.getElementById(toggle.getAttribute('aria-controls'));
        toggle.addEventListener('click', () => {
            panel.hidden = !panel.hidden;
            toggle.setAttribute('aria-expanded', String(!panel.hidden));
        });
    }
    let timer;
    if (document.activeElement === search) search.setSelectionRange(initial.length,initial.length);
    const schedule = event => {
        if (event.isComposing) return;
        clearTimeout(timer);
        if (search.value !== initial) timer = setTimeout(() => form.requestSubmit(),650);
    };
    search.addEventListener('input',schedule);
    search.addEventListener('compositionend',schedule);
    form.addEventListener('submit',() => clearTimeout(timer));
})();
</script>
HTML;
        }
        if ($search !== '' || $hasFacets) {
            $terms = preg_split('/\s+/u',$search,-1,PREG_SPLIT_NO_EMPTY) ?: [];
            $contains = static function ($value,$needle) {
                return function_exists('mb_stripos') ? mb_stripos($value,$needle,0,'UTF-8') !== false : stripos($value,$needle) !== false;
            };
            $rows = array_filter($rows,static function ($row,$id) use ($values,$columns,$terms,$contains,$hasFacets,$from,$to,$site,$item,$dimensions,$cellCache) {
                $cells = array_intersect_key($cellCache[$id] ?? $values($row,$id),$columns);
                if ($hasFacets) {
                    $date = isset($dimensions['date']) ? ($cells[$dimensions['date']] ?? '') : '';
                    if (preg_match('~^(\d{2})/(\d{2})/(\d{4})$~D',$date,$parts)) {
                        $date = $parts[3].'-'.$parts[2].'-'.$parts[1];
                    }
                    if (($from !== '' && ($date === '' || $date < $from)) || ($to !== '' && ($date === '' || $date > $to))
                        || ($site !== '' && (string)($cells[$dimensions['site']] ?? '') !== $site)
                        || ($item !== '' && (string)($cells[$dimensions['item']] ?? '') !== $item)) {
                        return false;
                    }
                }
                foreach ($cells as &$cell) {
                    $cell = is_array($cell) ? ($cell['text'] ?? '') : (is_scalar($cell) ? (string)$cell : '');
                }
                unset($cell);
                $text = implode(' ',$cells);
                foreach ($terms as $term) {
                    if (!$contains($text,$term)) {
                        return false;
                    }
                }
                return true;
            },ARRAY_FILTER_USE_BOTH);
        }
        if (self::$exportBuffer !== null) {
            self::sendTableExcel($columns,$rows,$values,$cellCache);
        }
        $total = count($rows);
        $pages = max(1,(int)ceil($total / 25));
        $page = min($pages,max(1,absint($_GET['frontier_page'] ?? 1)));
        return [array_slice($rows,($page-1)*25,25,true),$page,$pages,$total];
    }

    private static function pagination($page,$pages,$total) {
        $url = self::tableSearchUrl();
        foreach (['frontier_search','boq_from','boq_to','boq_site','boq_item'] as $key) {
            $value = self::tableQuery($key);
            if ($value !== '') {
                $url = add_query_arg($key,$value,$url);
            }
        }
        if ($pages === 1) {
            echo '<p role="status">'.esc_html(sprintf('%d records',$total)).'</p>';
            return;
        }
        echo '<nav aria-label="Table pagination"><p>';
        if ($page > 1) {
            echo '<a class="button" href="'.esc_url(add_query_arg('frontier_page',$page-1,$url)).'">Previous</a> ';
        }
        echo esc_html(sprintf('Page %d of %d · %d–%d of %d records',$page,$pages,($page-1)*25+1,min($page*25,$total),$total));
        if ($page < $pages) {
            echo ' <a class="button" href="'.esc_url(add_query_arg('frontier_page',$page+1,$url)).'">Next</a>';
        }
        echo '</p></nav>';
    }

    private static function renderRecords($listing,$rows,$kind,$mode,$choiceItems=[]) {
        $fields = self::FIELDS[$kind];
        unset($fields['password']);
        $fields += [
            'site'=>['combined_name'=>'Site Number with Name','status_date'=>'Status Date','status_remark'=>'Remark','updated_by'=>'Updated By'],
            'supplier'=>['combined_name'=>'Suppliers Name with Code'],
            'indent'=>['indent_number'=>'Indent No.','indent_date'=>'Indent Date','site_name'=>'Site Number with Name',
                'requested_name'=>'Requested By','line_number'=>'Sl No.','unit'=>'Unit','excess'=>'Excess Quantity','reason'=>'Reason'],
            'quote'=>['quote_date'=>'Quotation Date','comparison_name'=>'Compared By','reason'=>'Selection Reason','attachment'=>'Quotation File'],
            'po'=>['supplier'=>'Supplier Name with Code','reason'=>'Reason','received'=>'Received','waiting'=>'Pending GRN Approval','invoiced'=>'Invoiced','cancelled'=>'Cancelled','open'=>'Open to Receive','overdue'=>'Overdue Days','latest'=>'Latest Follow-up','next'=>'Next Follow-up'],
            'follow'=>['ordered'=>'Order Quantity','expected'=>'Expected Delivery','open'=>'Open to Receive','overdue'=>'Overdue Days','latest'=>'Latest Follow-up','next'=>'Next Follow-up'],
            'grn'=>['attachment'=>'GRN Slip'],
            'invoice'=>['matched'=>'Matched Amount','attachment'=>'Invoice Copy'],
        ][$kind] ?? [];
        $pendingPo = self::$screen === 'pending_po';
        if ($pendingPo) {
            $fields = [
                'site_name'=>'Site Number with Name','indent_number'=>'Indent No.','indent_date'=>'Indent Date',
                'due'=>'Required By','line_number'=>'Sl No.','item_name'=>'Item Name','unit'=>'Unit',
                'qty'=>'Indent Qty','ordered_quantity'=>'Qty Ordered','pending_quantity'=>'Pending to Order',
                'supplier'=>'Supplier Finalized','rate'=>'Rate Finalized',
            ];
        }
        $choosePending = self::$screen === 'pending_choose';
        $chooseMaster = self::$screen === 'choose_master';
        if ($choosePending || $chooseMaster) {
            $fields = ['site_name'=>'Site Number with Name','indent_number'=>'Indent No.','indent_date'=>'Indent Date',
                'line_number'=>'Sl No.','item_name'=>'Item Name','qty'=>'Qty','unit'=>'Unit'];
            $fields += [
                'supplier_1'=>'Supplier 1','rate_1'=>'Rate 1','supplier_2'=>'Supplier 2','rate_2'=>'Rate 2',
                'supplier_3'=>'Supplier 3','rate_3'=>'Rate 3',
            ];
            $fields += $choosePending ? ['quote_date'=>'Quotation Date','comparison_name'=>'Compared By'] : [
                'supplier'=>'Supplier Finalized','rate'=>'Rate Finalized','selection_date'=>'Selection Date',
                'comparison_name'=>'Compared By',
            ];
            if ($chooseMaster) {
                $choiceItems = self::supplierChoices($rows,true);
            }
        }
        $pendingQuote = self::$screen === 'pending_quote';
        if ($pendingQuote) {
            $fields = [
                'site_name'=>'Site Number with Name','item_name'=>'Item Name','qty'=>'Required Quantity',
                'due'=>'Required by','indent_number'=>'Indent No.','indent_date'=>'Indent Date',
                'requested_name'=>'Requested By','line_number'=>'Sl No.','unit'=>'Unit',
                'excess'=>'Excess Quantity','reason'=>'Reason',
            ];
        }
        $indentItems = self::$screen === 'indent_items';
        if ($indentItems) {
            $fields = self::indentItemColumns();
        }
        $poRegister = self::$screen === 'po';
        $poItems = self::$screen === 'po_items';
        if ($poRegister) {
            $fields = ['po_date'=>'PO Date','site_name'=>'Site Name','ref'=>'PO No.',
                'supplier'=>'Supplier','pdf'=>'PDF','timestamp'=>'Timestamp','qty'=>'Total Quantity',
                'received'=>'Quantity Received','open'=>'Pending to Receive','due'=>'PO Target Date'];
        } elseif ($poItems) {
            $fields = ['timestamp'=>'Timestamp','ref'=>'PO NO','po_date'=>'PO Date','site_name'=>'Site Name',
                'indent_date'=>'Indent Date','indent_number'=>'Indent Number','requested'=>'Requested By',
                'due'=>'Target Supply Date','line_number'=>'Sl No.','item_name'=>'Item Name','unit'=>'Units',
                'supplier'=>'Supplier Finalized','rate'=>'Rate Finalized','qty'=>'Order Quantity','amount'=>'Total Amount',
                'po_supplier'=>'PO No. with Supplier Name','received'=>'Quantity Received','open'=>'Pending to Receive GRN',
                'brand'=>'Brand','remarks'=>'Remark'];
        }
        $grnScreen = self::$screen;
        $grnFields = [
            'grn'=>['grn_date'=>'GRN Date','grn_no'=>'GRN No.','supplier'=>'Supplier','grn_pdf'=>'GRN PDF',
                'timestamp'=>'Timestamp','slip_no'=>'Slip Number','site_name'=>'Site Name','grn_slip_copy'=>'GRN Slip Copy'],
            'grn_items'=>['timestamp'=>'Timestamp','grn_no'=>'GRN No.','grn_date'=>'GRN Date','supplier'=>'Supplier Name',
                'po_no'=>'PO No.','po_date'=>'PO Date','prepared'=>'Prepared By','site_name'=>'Site Name',
                'sl'=>'Sl No.','item_name'=>'Item Name','ordered'=>'Quantity Ordered','already'=>'Quantity Already Received',
                'pending'=>'Quantity Pending to Receive','received'=>'Quantity Received','slip_no'=>'GRN Slip No.'],
            'pending_grn'=>['ref'=>'PO No.','po_date'=>'PO Date','due'=>'Target Date','supplier'=>'Supplier',
                'supplier_date'=>'Supplier Date','item_name'=>'Item Name','qty'=>'Order Qty','received'=>'Already Received','open'=>'Pending'],
            'grn_slip'=>['site_name'=>'Site Name','supplier'=>'Supplier Name','grn_date'=>'GRN Date',
                'grn_no'=>'GRN No.','slip_no'=>'Slip No.'],
        ];
        $isGrnView = isset($grnFields[$grnScreen]);
        if ($isGrnView) {
            $fields = $grnFields[$grnScreen];
        }
        $showStatus = !$indentItems && !$chooseMaster && !$pendingPo && !$poRegister && !$poItems && !$isGrnView;
        $showActions = $grnScreen !== 'grn' && !in_array($mode,['items','selected'],true);
        $rowValues = static function ($row) use ($rows,$kind,$indentItems,$choosePending,$chooseMaster,$choiceItems,$pendingQuote) {
            [$values,$status] = $indentItems ? [self::indentItemValues($row,$rows),$row['status']] : self::recordValues($row,$rows,$kind);
            if ($choosePending || $chooseMaster) {
                $indentId = (int)($row['data']['indent'] ?? 0);
                $indent = $rows[$indentId] ?? [];
                $values = self::supplierIndentDetails($indent,$rows) + $values;
                foreach (array_values(array_slice($choiceItems[$indentId] ?? [],0,3)) as $index=>$quote) {
                    $slot = $index + 1;
                    $values['supplier_'.$slot] = self::supplierDisplay($quote['data'],$rows);
                    $values['rate_'.$slot] = $quote['data']['rate'];
                }
                if ($choosePending) {
                    $status = 'pending_supplier_selection';
                } else {
                    $data = $row['data'];
                    $values['comparison_name'] = $data['compared_by'] ?? $data['comparison_name'] ?? '';
                    $values['selection_date'] = self::indentDisplayDate($data['selection_date'] ?? substr($data['compared_at'] ?? '',0,10));
                }
            }
            if ($pendingQuote) {
                $indent = $row['data'];
                $boq = $rows[$indent['boq'] ?? 0]['data'] ?? [];
                $site = $rows[$indent['site'] ?? ($boq['site'] ?? 0)]['data'] ?? [];
                $values['site_name'] = self::siteNumberWithName($site);
                $values['item_name'] = $boq['name'] ?? '';
                $values['indent_number'] = $indent['indent_number'] ?? '#'.$row['id'];
                $values['line_number'] = $indent['line_number'] ?? 1;
                $values['unit'] = $indent['unit'] ?? ($boq['unit'] ?? '');
                $status = 'pending_supplier_quotation';
            }
            return [$values,$status];
        };
        $searchFields = $fields + ($showStatus ? ['_status'=>'Status'] : []);
        [$pageRows,$page,$pages,$total] = self::pagedRows($listing,$searchFields,static function ($row) use ($rowValues,$rows) {
            [$values,$status] = $rowValues($row);
            $values['_status'] = ucwords(str_replace('_',' ',$status));
            $values['attachment'] = !empty($row['filename']) && self::canFile($row['kind'])
                ? self::exportLink($row['filename'],'frontier_file',$row['id']) : '';
            if (self::$screen === 'po') {
                $values['pdf'] = self::exportLink('PDF','frontier_po_pdf',$row['id']);
            } elseif (self::$screen === 'grn') {
                $values['grn_pdf'] = self::exportLink('PDF','frontier_grn_pdf',$row['id']);
            }
            if ($row['kind'] === 'grn' && self::canFile('grn')) {
                foreach (self::goodsReceiptGroup($rows,$row) as $line) {
                    if (!empty($line['filename'])) {
                        $values['grn_slip_copy'] = self::exportLink($line['filename'],'frontier_file',$line['id']);
                        break;
                    }
                }
            }
            foreach ($values as $key=>$value) {
                if (strpos($key,'quotation_') === 0 && is_array($value)) {
                    $values[$key] = !empty($value['filename']) && self::canFile('quote')
                        ? self::exportLink($value['filename'],'frontier_file',$value['id']) : '';
                }
            }
            return $values;
        });
        echo '<div class="frontier-table frontier-master-table" tabindex="0" role="region" aria-label="Records"><table class="widefat striped"><thead><tr>';
        foreach ($fields as $label) {
            echo '<th scope="col">'.esc_html($label).'</th>';
        }
        if ($showStatus) {
            echo '<th scope="col">Status</th>';
        }
        $actionClass = $pendingPo ? ' class="frontier-pending-po-action"' : ($poRegister ? ' class="frontier-po-actions"' : '');
        if ($showActions) {
            echo '<th scope="col"'.$actionClass.'>Actions</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($pageRows as $row) {
            [$values,$status] = $rowValues($row);
            echo '<tr>';
            foreach ($fields as $field=>$label) {
                echo '<td>';
                if ($field === 'grn_pdf' || ($field === 'pdf' && $poRegister)) {
                    $action = $field === 'grn_pdf' ? 'frontier_grn_pdf' : 'frontier_po_pdf';
                    $url = add_query_arg(['action'=>$action,'id'=>$row['id']],admin_url('admin-post.php'));
                    echo '<a class="button frontier-download" href="'.esc_url(wp_nonce_url($url,$action.'_'.$row['id'])).'">PDF</a>';
                } elseif ($field === 'grn_slip_copy') {
                    if (self::canFile('grn')) {
                        foreach (self::goodsReceiptGroup($rows,$row) as $line) {
                            if (!empty($line['filename'])) {
                                $url = add_query_arg(['action'=>'frontier_file','id'=>$line['id']],admin_url('admin-post.php'));
                                echo '<a href="'.esc_url(wp_nonce_url($url,'frontier_file_'.$line['id'])).'">'.esc_html($line['filename']).'</a>';
                                break;
                            }
                        }
                    }
                } elseif ($indentItems && strpos($field,'quotation_') === 0) {
                    if (isset($values[$field]) && self::canFile('quote')) {
                        $quote = $values[$field];
                        $url = add_query_arg(['action'=>'frontier_file','id'=>$quote['id']],admin_url('admin-post.php'));
                        echo '<a href="'.esc_url(wp_nonce_url($url,'frontier_file_'.$quote['id'])).'">'.esc_html($quote['filename']).'</a>';
                    }
                } elseif ($field === 'attachment' && $row['filename'] && self::canFile($row['kind'])) {
                    $url = add_query_arg(['action'=>'frontier_file','id'=>$row['id']],admin_url('admin-post.php'));
                    echo '<a href="'.esc_url(wp_nonce_url($url,'frontier_file_'.$row['id'])).'">'.esc_html($row['filename']).'</a>';
                } else {
                    $value = esc_html((string)($values[$field] ?? ''));
                    if ($poItems && $field === 'remarks') {
                        echo nl2br($value,false);
                    } elseif ($kind === 'supplier' && $field === 'term' && $value !== '') {
                        echo '<details class="frontier-term"><summary aria-label="Toggle supplier terms">';
                        echo '<span class="frontier-term-preview">'.$value.'</span><span class="frontier-term-label">Terms</span>';
                        echo '<span class="frontier-term-arrow" aria-hidden="true">▾</span></summary>';
                        echo '<div class="frontier-term-full">'.nl2br($value,false).'</div></details>';
                    } else {
                        echo $kind === 'supplier' && $field === 'address' ? nl2br($value,false) : $value;
                    }
                }
                echo '</td>';
            }
            if ($showStatus) {
                echo '<td>'.esc_html(ucwords(str_replace('_',' ',$status))).'</td>';
            }
            if ($showActions) {
                echo '<td'.$actionClass.'>';
                echo '<div class="frontier-row-actions">';
                self::actions($row,$rows);
                if (in_array($kind,['employee','item','units'],true) && self::can($kind)) {
                    self::deleteAction($row,'delete_master','Delete record','Delete this record?');
                }
                echo '</div>';
                echo '</td>';
            }
            echo '</tr>';
        }
        if (!$total) {
            echo '<tr><td colspan="'.(count($fields)+(int)$showStatus+(int)$showActions).'">No records yet.</td></tr>';
        }
        echo '</tbody></table></div>';
        self::pagination($page,$pages,$total);
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
        [$pageRows,$page,$pages,$total] = self::pagedRows($received,['BOQ Item','Site Number with Name','Accepted Stock Received','Unit'],static function ($quantity,$id) use ($rows) {
            $item = $rows[$id]['data'];
            return [$item['name'],self::siteNumberWithName($rows[$item['site']]['data'] ?? []),round($quantity,3),$item['unit']];
        });
        echo '<div class="frontier-table"><table class="widefat striped"><thead><tr><th>BOQ Item</th><th>Site Number with Name</th><th>Accepted Stock Received</th><th>Unit</th></tr></thead><tbody>';
        foreach ($pageRows as $id=>$quantity) {
            $item = $rows[$id]['data'];
            echo '<tr><td>'.esc_html($item['name']).'</td><td>'.esc_html(self::siteNumberWithName($rows[$item['site']]['data'] ?? [])).'</td><td>'.esc_html(round($quantity,3)).'</td><td>'.esc_html($item['unit']).'</td></tr>';
        }
        if (!$total) {
            echo '<tr><td colspan="4">No stock receipts approved yet.</td></tr>';
        }
        echo '</tbody></table></div>';
        self::pagination($page,$pages,$total);
    }

    private static function frontUrl($kind) {
        $pages = (array)get_option('frontier_pages',[]);
        $id = absint($pages[$kind] ?? 0);
        if (!$id || get_post_status($id) !== 'publish') {
            return false;
        }
        if ($kind !== 'home' && get_option('show_on_front') === 'page'
            && $id === (int)get_option('page_on_front') && ($dashboard = self::frontUrl('home'))) {
            return add_query_arg('frontier_view',$kind,$dashboard);
        }
        return get_permalink($id);
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
        $class = in_array($action,['create','edit','complete_site','cancel_site','save_supplier_selection','create_purchase_orders','create_goods_receipt','link_po_invoice'],true) ? ' class="frontier-entry"' : '';
        if ($action === 'create_purchase_orders') {
            $class = ' class="frontier-entry frontier-po-form"';
        }
        if ($action === 'save_quotations') {
            $class = ' class="frontier-entry frontier-quotation-form"';
        }
        if ($action === 'create_indent') {
            $class = ' class="frontier-entry frontier-indent-form"';
        }
        if (in_array($action,['create_boq','edit_boq'],true)) {
            $class = ' class="frontier-entry frontier-boq-form"';
        }
        echo '<form'.$class.' method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('frontier_save');
        foreach (['action'=>'frontier_save','kind'=>$kind,'operation'=>$action,'id'=>$id,'screen'=>self::$screen,'surface'=>self::$front ? 'front' : 'admin'] as $key=>$value) {
            echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr($value).'">';
        }
    }

    private static function requiredLabel($label,$required=true) {
        return '<span>'.esc_html($label).($required ? ' <span aria-hidden="true" style="color:#b42318">*</span>' : '').'</span>';
    }

    private static function uploadField($kind,$required=false) {
        echo '<p><label>'.self::requiredLabel(self::FILES[$kind],$required).' <input type="file" name="frontier_document" accept=".pdf,.png,.jpg,.jpeg" '.($required ? 'required' : '').'></label></p>';
    }

    private const SUPPLIER_TEXT_LIMITS = ['address'=>5000,'term'=>20000];

    private static function supplierText($value) {
        $value = str_replace(["\r\n","\r"],"\n",$value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u','',$value);
        return trim(preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u','',$value ?? '') ?? '');
    }

    private static function optionalField($kind,$field) {
        return in_array($field,['reject','tax','freight'],true)
            || ($field === 'note' && in_array($kind,['quote','match'],true))
            || ($kind === 'supplier' && !in_array($field,['ref','name'],true))
            || ($kind === 'employee' && in_array($field,['email','department'],true))
            || ($kind === 'site' && $field === 'telegram');
    }

    private static function field($rows,$kind,$field,$label,$value,$hasLogin=false) {
        $required = $field === 'password' ? !$hasLogin : ($field !== 'reorder' && !self::optionalField($kind,$field));
        echo '<p><label>'.self::requiredLabel($label,$required);
        if ($kind === 'supplier' && isset(self::SUPPLIER_TEXT_LIMITS[$field])) {
            printf('<textarea name="%s" rows="4" maxlength="%d">%s</textarea>',
                esc_attr($field),self::SUPPLIER_TEXT_LIMITS[$field],esc_textarea($value));
        } elseif ($kind === 'employee' && $field === 'password') {
            echo '<input type="password" name="password" autocomplete="new-password" maxlength="4096"'.($required ? ' required' : '').'>';
        } elseif (isset(self::FIELDS[$field])) {
            echo '<select name="'.esc_attr($field).'"'.(self::optionalField($kind,$field) ? '' : ' required').'><option value="">Select</option>';
            foreach (self::eligible($rows,$kind,$field) as $row) {
                $optionLabel = self::label($row,$rows);
                if ($field === 'employee') {
                    $optionLabel = $row['data']['name'];
                }
                echo '<option value="'.esc_attr($row['id']).'" '.selected($value,$row['id'],false).'>'.esc_html($optionLabel).'</option>';
            }
            echo '</select>';
        } elseif ($field === 'reorder') {
            echo '<input type="checkbox" name="reorder" value="1">';
        } else {
            $numeric = in_array($field,['qty','rate','tax','freight','reject','amount'],true);
            $type = $numeric ? 'number' : (['due'=>'date','email'=>'email','mobile'=>'tel','contact'=>'tel','alternate'=>'tel'][$field] ?? 'text');
            if ($kind === 'supplier' && $field === 'email') {
                $type = 'text';
            }
            $optional = self::optionalField($kind,$field);
            printf('<input type="%s" name="%s" value="%s" %s %s>',esc_attr($type),esc_attr($field),esc_attr($value),
                $field === 'amount' ? 'step="0.01" min="0"' : ($numeric ? 'step="0.001" min="0"' : 'maxlength="200"'), $optional ? '' : 'required');
        }
        echo '</label></p>';
    }

    private static function siteStatusForm($site,$action) {
        $label = $action === 'complete_site' ? 'Complete Site' : 'Cancel Site';
        self::formStart('site',$action,$site['id']);
        echo '<h2>'.esc_html($label.' · '.$site['data']['name']).'</h2><div class="frontier-fields">';
        self::field([],'site','due','Date',current_time('Y-m-d'));
        echo '<p><label>'.self::requiredLabel('Remark').'<textarea name="status_remark" rows="3" maxlength="2000" required></textarea></label></p>';
        echo '<p><label>Updated By <input type="text" readonly value="'.esc_attr(wp_get_current_user()->display_name).'"></label></p></div>';
        self::submitButton($label,true);
        echo ' <a class="button" href="'.esc_url(self::url('site')).'">Back to Site Master</a></form>';
    }

    private static function actions($row,$rows) {
        $kind = $row['kind'];
        $status = $row['status'];
        $mode = self::SCREENS[self::$screen][1] ?? 'combined';
        $next = [
            'pending_quote'=>['quote_form','Update'],
            'pending_choose'=>['choose_form','Update'],
            'pending_po'=>['po_form','Create PO'],
            'pending_grn'=>['grn_form','Create Goods Receipt'],
        ][self::$screen] ?? null;
        if ($next) {
            $url = add_query_arg(['add'=>1,'source'=>$row['id']],self::url($next[0]));
            echo '<a class="button frontier-add" href="'.esc_url($url).'">'.esc_html($next[1]).'</a>';
            return;
        }
        if (in_array($mode,['items','selected'],true)) {
            return;
        }
        if ((isset(self::MASTERS[$kind]) || $kind === 'site') && self::can($kind === 'site' ? 'sites' : $kind)) {
            self::editAction($kind,$row['id'],'Edit record');
        }
        if ($kind === 'site' && self::can('sites') && !in_array($status,['completed','cancelled'],true)) {
            foreach (['complete_site'=>'Complete Site','cancel_site'=>'Cancel Site'] as $action=>$label) {
                $url = add_query_arg(['site_action'=>$action,'site_id'=>$row['id']],self::url('site'));
                echo ' <a class="button" href="'.esc_url($url).'">'.esc_html($label).'</a>';
            }
        }
        if ($kind === 'indent') {
            if (self::can('indent') && in_array($status,['draft','returned','approved'],true)) {
                self::editAction($kind,$row['id'],'Edit indent');
                if ($status !== 'approved') {
                    self::button($row,'submit','Submit for approval');
                }
            }
            if ($status === 'pending' && self::can('approve')) {
                self::button($row,'approve','Approve');
                echo '<details class="frontier-return-action"><summary class="button">Return</summary>';
                self::button($row,'return','Confirm Return',true);
                echo '</details>';
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
                self::deleteAction($row,'delete','Delete draft','Delete this draft?');
            }
            if (in_array($status,['draft','issued'],true)) {
                $linked = false;
                $openLine = null;
                foreach (self::purchaseOrderGroup($rows,$row) as $line) {
                    $linked = $linked || self::linked($rows,$line['id']);
                    if ($line['status'] === 'issued' && self::balance($rows,$line) > 0) {
                        $openLine = $openLine ?? $line['id'];
                    }
                }
                if (!$linked) {
                    echo '<details class="frontier-po-cancel"><summary class="button">Cancel PO</summary>';
                    self::button($row,'cancel_po','Confirm cancellation',true);
                    echo '</details>';
                } elseif ($openLine !== null && self::canView('cancel') && self::can('cancel')) {
                    $url = add_query_arg(['add'=>1,'source'=>$openLine],self::url('cancel'));
                    echo '<a class="button" href="'.esc_url($url).'">Cancel PO Items</a>';
                } elseif (self::$screen === 'po') {
                    echo '<small>No cancellable balance</small>';
                }
            } elseif (self::$screen === 'po' && $status === 'cancelled') {
                echo '<small>Cancelled</small>';
            }
        }
        if ($kind === 'grn' && $mode === 'slip' && self::can('grn')) {
            echo '<details><summary class="button frontier-upload">Upload</summary>';
            self::formStart($kind,'attach',$row['id']);
            self::uploadField($kind,true);
            self::submitButton('Upload slip');
            echo '</form></details>';
        }
    }

    private static function editAction($kind,$id,$label) {
        $url = add_query_arg('edit',$id,self::url($kind));
        echo '<a class="button frontier-action-icon frontier-action-edit" aria-label="'.esc_attr($label).'" title="'.esc_attr($label).'" href="'.esc_url($url).'"><span aria-hidden="true">✎</span></a>';
    }

    private static function deleteAction($row,$operation,$label,$message) {
        echo '<details class="frontier-delete-action"><summary class="button frontier-action-icon frontier-action-delete" aria-label="'.esc_attr($label).'" title="'.esc_attr($label).'"></summary>';
        echo '<p>'.esc_html($message).'</p>';
        self::button($row,$operation,'Confirm Delete');
        echo '</details>';
    }

    private static function button($row,$operation,$label,$reason=false) {
        self::formStart($row['kind'],$operation,$row['id']);
        if ($reason) {
            echo '<label>'.self::requiredLabel('Reason').' <input name="reason" required maxlength="200"></label> ';
        }
        self::submitButton($label);
        echo '</form>';
    }

    private static function recordValues($row,$rows,$screenKind) {
        $kind = $row['kind'];
        $data = $row['data'];
        if ($kind === 'grn' && in_array(self::$screen,['grn','grn_items','grn_slip'],true)) {
            return [self::goodsReceiptValues($rows,$row),$row['status']];
        }
        if ($kind === 'po' && self::$screen === 'pending_grn') {
            $values = self::purchaseOrderValues($rows,$row);
            $values['open'] = self::balance($rows,$row);
            $values['received'] = self::sum($rows,'grn','po',$row['id']);
            $values['supplier_date'] = self::indentDisplayDate($data['supplier_date'] ?? '');
            return [$values,$row['status']];
        }
        if ($kind === 'po' && in_array(self::$screen,['po','po_items'],true)) {
            return [self::purchaseOrderValues($rows,$row,self::$screen === 'po'),$row['status']];
        }
        $values = [];
        foreach (self::FIELDS[$kind] as $field=>$label) {
            if ($field === 'password' || !isset($data[$field]) || $data[$field] === '') {
                continue;
            }
            $value = $data[$field];
            if (isset(self::FIELDS[$field],$rows[$value])) {
                $linked = $rows[$value];
                $value = in_array($field,['category','item_type','employee'],true) ? $linked['data']['name'] : self::label($linked,$rows);
            } elseif ($field === 'due') {
                $value = implode('/',array_reverse(explode('-',$value)));
            } elseif ($field === 'reorder') {
                $value = $value ? 'Yes' : 'No';
            }
            $values[$field] = $value;
        }
        if (in_array($kind,['quote','po','invoice'],true)) {
            $values['supplier'] = self::supplierDisplay($data,$rows);
        }
        if (in_array($kind,['supplier','site'],true) && !empty($data['ref'])) {
            $values['combined_name'] = $data['ref'].' - '.$data['name'];
        }
        if (self::$screen === 'pending_po' && isset($rows[$data['indent'] ?? 0])) {
            $indent = $rows[$data['indent']];
            $values = self::supplierIndentDetails($indent,$rows) + $values;
            $due = $indent['data']['due'] ?? '';
            if ($due === '') {
                $group = self::indentGroup($indent);
                foreach ($rows as $line) {
                    if ($line['kind'] !== 'indent' || self::indentGroup($line) !== $group) {
                        continue;
                    }
                    $lineDue = $line['data']['due'] ?? '';
                    if ($lineDue !== '' && ($due === '' || $lineDue < $due)) {
                        $due = $lineDue;
                    }
                }
            }
            $values['due'] = self::indentDisplayDate($due);
            $values['pending_quantity'] = self::available($rows,$indent);
            $values['ordered_quantity'] = round($indent['data']['qty'] - $values['pending_quantity'],3);
        }
        $values['reason'] = $data['reason'] ?? $data['selection_reason'] ?? '';
        if ($kind === 'quote') {
            $values['quote_date'] = self::indentDisplayDate($data['quote_date'] ?? '');
            $values['comparison_name'] = $data['comparison_name'] ?? '';
        }
        if ($kind === 'indent' && !empty($data['indent_number'])) {
            foreach (['indent_number','requested_name','line_number','unit','excess'] as $key) {
                $values[$key] = $data[$key] ?? '';
            }
            $values['indent_date'] = implode('/',array_reverse(explode('-',$data['indent_date'])));
            $values['site_name'] = self::siteNumberWithName($rows[$data['site']]['data'] ?? []);
        }
        if ($kind === 'site' && !empty($data['status_date'])) {
            $values['status_date'] = implode('/',array_reverse(explode('-',$data['status_date'])));
            $values['status_remark'] = $data['status_remark'] ?? '';
            $values['updated_by'] = $data['updated_by'] ?? '';
        }
        if ($kind === 'po' && ($row['status'] === 'issued' || self::$screen === 'pending_grn')) {
            $id = $row['id'];
            $values['received'] = self::sum($rows,'grn','po',$id,'qty','approved');
            $values['waiting'] = self::sum($rows,'grn','po',$id,'qty','pending');
            $values['invoiced'] = self::sum($rows,'match','po',$id);
            $values['cancelled'] = self::sum($rows,'cancel','po',$id);
            $values['open'] = self::balance($rows,$row);
            if ($values['open'] > 0 && !empty($data['due'])) {
                $today = new DateTimeImmutable(current_time('Y-m-d'));
                $due = new DateTimeImmutable($data['due']);
                $values['overdue'] = max(0,(int)$due->diff($today)->format('%r%a'));
            }
            foreach ($rows as $follow) {
                if ($follow['kind'] === 'follow' && (int)$follow['data']['po'] === (int)$id) {
                    $values['latest'] = $follow['data']['note'];
                    $values['next'] = implode('/',array_reverse(explode('-',$follow['data']['due'])));
                    break;
                }
            }
            if ($screenKind === 'follow') {
                $values['po'] = self::label($row,$rows);
                $values['ordered'] = $values['qty'];
                $values['expected'] = $values['due'] ?? '';
                unset($values['due']);
            }
        }
        if ($kind === 'grn' && !$row['filename']) {
            $values['attachment'] = 'Slip pending';
        }
        $status = $row['status'];
        if ($kind === 'employee') {
            $access = self::assignedViewAccess(absint($data['wp_user_id'] ?? 0));
            $status = ($access['status'] ?? '') === 'inactive' ? 'in_active' : 'active';
        }
        if ($kind === 'invoice') {
            $matchedAmount = 0;
            foreach ($rows as $match) {
                if ($match['kind'] === 'match' && (int)($match['data']['invoice'] ?? 0) === (int)$row['id']) {
                    $matchedAmount += round((float)$match['data']['qty'] * (float)$match['data']['rate'],2);
                }
            }
            $values['amount'] = isset($data['amount']) ? number_format($data['amount'],2) : '';
            $values['matched'] = number_format($matchedAmount,2);
            $matchedQty = self::sum($rows,'match','invoice',$row['id']);
            $complete = isset($data['qty']) ? $matchedQty >= $data['qty']
                : isset($data['amount']) && round($matchedAmount,2) >= $data['amount'];
            $status = $matchedQty <= 0 ? 'pending_match' : ($complete ? 'matched' : 'partially_matched');
        }
        return [$values,$status];
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
            if ($field === 'password' || ($field === 'ref' && in_array($kind,['site','supplier'],true))) {
                continue;
            }
            $raw = $_POST[$field] ?? '';
            $value = $kind === 'supplier' && is_string($raw)
                ? self::supplierText(wp_unslash($raw)) : self::input($field);
            if ($value === '' && self::optionalField($kind,$field) && isset(self::FIELDS[$field])) {
                $data[$field] = '';
                continue;
            }
            if (isset(self::FIELDS[$field])) {
                $record = self::eligible($rows,$kind,$field)[absint($value)] ?? null;
                self::requireRule($record,'Choose a valid '.$label.'.');
                $value = (int)$record['id'];
            } elseif ($field === 'amount') {
                self::requireRule(preg_match('/^\d{1,12}(?:\.\d{1,2})?$/D',$value),'Enter a valid Invoice Amount.');
                $value = (float)$value;
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
                if ($kind !== 'supplier' && $field === 'email' && $value !== '') {
                    self::requireRule(is_email($value),'Enter a valid email address.');
                }
                $limit = $kind === 'supplier' ? (self::SUPPLIER_TEXT_LIMITS[$field] ?? 200) : 200;
                self::requireRule(($value !== '' || $optional) && strlen($value) <= $limit,'Check '.$label.' (maximum '.$limit.' bytes).');
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

    private static function masterCodeNumber($code) {
        return preg_match('/^S\s*-\s*(\d{1,9})$/iD',trim($code),$match) ? (int)$match[1] : 0;
    }

    private static function formatMasterCode($number) {
        return sprintf('S - %04d',$number);
    }

    private static function lastMasterCode($rows,$kind) {
        $last = 0;
        foreach ($rows as $row) {
            if ($row['kind'] === $kind) {
                $last = max($last,self::masterCodeNumber($row['data']['ref'] ?? ''));
            }
        }
        return $last;
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
            case 'quote':
            case 'invoice':
                $data['name'] = $rows[$data['supplier']]['data']['name'];
                if ($kind === 'quote') {
                    $data['quote_timestamp'] = current_time('mysql');
                }
                break;
            case 'po':
                $quote = $rows[$data['quote']]['data'];
                $data['indent'] = $quote['indent'];
                $data['name'] = $quote['name'];
                if (!empty($quote['supplier'])) {
                    $data['supplier'] = $quote['supplier'];
                }
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
                self::requireRule(self::sameSupplier($po['data'],$invoice['data']),'The invoice and PO suppliers must match.');
                self::requireRule($data['qty'] <= self::pendingInvoiceQuantity($rows,$po),'Invoice quantity exceeds the PO item balance.');
                self::requireRule($data['qty'] <= round($grn['data']['qty'] - self::sum($rows,'match','grn',$grn['id']),3),'Invoice quantity exceeds the unmatched GRN quantity.');
                self::requireRule(!isset($invoice['data']['qty']) || $data['qty'] <= round($invoice['data']['qty'] - self::sum($rows,'match','invoice',$invoice['id']),3),'Quantity exceeds the unmatched invoice balance.');
                self::requireRule($data['rate'] === (float)$po['data']['rate'] || $data['note'] !== '','Explain the variance from the PO rate.');
                break;
        }
        if (isset($data['ref']) && in_array($kind,['po','invoice','grn'],true)) {
            foreach ($rows as $row) {
                $sameSupplier = $kind !== 'invoice' || self::sameSupplier($data,$row['data']);
                self::requireRule($row['kind'] !== $kind || !$sameSupplier || strcasecmp($data['ref'],$row['data']['ref']) !== 0,'That reference already exists.');
            }
        }
    }

    private static function validateIndent($rows,&$data,$id) {
        $used = 0;
        foreach ($rows as $row) {
            if ($row['kind'] !== 'indent' || (int)$row['id'] === (int)$id || (int)$row['data']['boq'] !== (int)$data['boq']) {
                continue;
            }
            if (!empty($data['indent_number'])) {
                self::requireRule((int)($row['data']['indent_number'] ?? 0) !== (int)$data['indent_number'],
                    'This BOQ item is already on the same indent.');
            }
            if (in_array($row['status'],['pending','approved'],true)) {
                $used += $row['data']['qty'];
            }
        }
        $boq = $rows[$data['boq']]['data'];
        if (!empty($data['indent_number'])) {
            self::requireRule((int)$boq['site'] === (int)$data['site'],'Select a BOQ item from the same site.');
            $data['unit'] = $boq['unit'];
            $data['already_indented'] = round($used,3);
            $data['excess'] = max(0,round($data['qty'] - max(0,$boq['qty']-$used),3));
        } else {
            self::requireRule(round($used + $data['qty'],3) <= $boq['qty'],'This exceeds the available BOQ quantity.');
        }
    }

    private static function transition($rows,$row,$action) {
        $kind = $row['kind'];
        $status = $row['status'];
        $data = $row['data'];
        $update = [];
        switch ($action) {
            case 'complete_site':
            case 'cancel_site':
                self::requireRule($kind === 'site' && !in_array($status,['completed','cancelled'],true),'This site has already been closed or is unavailable.');
                $dateText = self::input('due');
                $date = DateTimeImmutable::createFromFormat('!Y-m-d',$dateText);
                $remark = self::input('status_remark');
                self::requireRule($date && $date->format('Y-m-d') === $dateText,'Enter a valid date.');
                self::requireRule($remark !== '' && strlen($remark) <= 2000,'Enter a remark of up to 2,000 bytes.');
                $user = wp_get_current_user();
                $data['status_date'] = $dateText;
                $data['status_remark'] = $remark;
                $data['updated_by'] = $user->display_name;
                $data['updated_by_id'] = $user->ID;
                $status = $action === 'complete_site' ? 'completed' : 'cancelled';
                break;
            case 'edit':
                if (isset(self::MASTERS[$kind]) || $kind === 'site') {
                    $fields = self::readFields($rows,$kind);
                    if (in_array($kind,['site','supplier'],true)) {
                        $fields['ref'] = ($row['data']['ref'] ?? '') ?: self::formatMasterCode(self::nextNumber($kind,self::lastMasterCode($rows,$kind)));
                    }
                    self::validateMaster($rows,$kind,$fields,$row['id']);
                    $data = $fields + $row['data'];
                    break;
                }
                self::requireRule($kind === 'indent' && in_array($status,['draft','returned','approved'],true),'This indent cannot be edited.');
                $newData = self::readFields($rows,$kind);
                if (!empty($data['indent_number'])) {
                    $newData += $data;
                    self::validateIndent($rows,$newData,$row['id']);
                }
                if ($status === 'approved') {
                    foreach ($rows as $record) {
                        self::requireRule(!in_array($record['kind'],['po','quote'],true) || (int)$record['data']['indent'] !== (int)$row['id'],'An indent linked to purchasing records is locked.');
                    }
                    if (empty($data['indent_number'])) {
                        self::validateIndent($rows,$newData,$row['id']);
                    }
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
                $data['decision_at'] = current_time('mysql');
                $data['decision_by'] = wp_get_current_user()->display_name;
                if ($action === 'approve') {
                    unset($data['reason']);
                }
                break;
            case 'select':
                self::requireRule($kind === 'quote' && $status !== 'selected' && $rows[$data['indent']]['status'] === 'approved','This quotation cannot be selected.');
                $status = 'selected';
                $data['compared_at'] = current_time('mysql');
                $data['compared_by'] = wp_get_current_user()->display_name;
                break;
            case 'issue':
                self::requireRule($kind === 'po' && $status === 'draft','Only a draft PO can be issued.');
                $status = 'issued';
                $data['issued_at'] = current_time('mysql');
                break;
            case 'cancel_po':
                self::requireRule($kind === 'po' && in_array($status,['draft','issued'],true) && !self::linked($rows,$row['id']),'Use line cancellation for an order with procurement activity.');
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

    private static function employeeViewAccessFields($data) {
        $access = self::assignedViewAccess(absint($data['wp_user_id'] ?? 0));
        $role = $access['role'] ?? 'employee';
        echo '<p><label>'.self::requiredLabel('Role').'<select name="frontier_employee_role" required>';
        foreach (['admin'=>'Admin','employee'=>'Employee'] as $key=>$label) {
            echo '<option value="'.esc_attr($key).'" '.selected($role,$key,false).'>'.esc_html($label).'</option>';
        }
        echo '</select></label></p>';
        echo '<p><label>'.self::requiredLabel('Status').'<select name="frontier_employee_status" required>';
        foreach (['active'=>'Active','inactive'=>'In Active'] as $key=>$label) {
            echo '<option value="'.esc_attr($key).'" '.selected($access['status'] ?? 'active',$key,false).'>'.esc_html($label).'</option>';
        }
        echo '</select></label></p>';
    }

    private static function employeeViewAccessGroups($data) {
        $access = self::assignedViewAccess(absint($data['wp_user_id'] ?? 0));
        echo '<section data-frontier-view-access'.(($access['role'] ?? '') === 'admin' ? ' hidden' : '').'><h2>View Access</h2>';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,210px),1fr));gap:12px;margin-bottom:16px">';
        foreach (self::GROUPS as $group=>$views) {
            echo '<fieldset style="min-width:0;border:1px solid #cbd6e4;border-radius:8px;padding:10px"><legend>'.esc_html($group).'</legend>';
            foreach ($views as $view=>$label) {
                $checked = ($access['role'] ?? '') !== 'employee' || in_array($view,$access['views'] ?? [],true);
                echo '<label style="display:block;margin:4px 0"><input type="checkbox" name="frontier_employee_views[]" value="'
                    .esc_attr($view).'" '.checked($checked,true,false).'> '.esc_html($label).'</label>';
            }
            echo '</fieldset>';
        }
        echo '</div></section>';
        echo <<<'HTML'
<script>
(() => {
    const form = document.currentScript.closest('form');
    const role = form.elements.frontier_employee_role;
    const access = form.querySelector('[data-frontier-view-access]');
    const update = () => { access.hidden = role.value === 'admin'; };
    role.addEventListener('change',update);
    update();
})();
</script>
HTML;
    }

    private static function employeeAccount($data) {
        $linkedId = absint($data['wp_user_id'] ?? 0);
        $account = $linkedId ? get_userdata($linkedId) : null;
        self::requireRule(current_user_can('promote_users') && ($linkedId
            ? current_user_can('edit_user',$linkedId)
            : current_user_can('create_users')), 'You do not have permission to manage employee logins.');
        self::requireRule($linkedId || get_role('frontier_requester'), 'The Frontier Requester role is unavailable.');
        self::requireRule(strlen($data['login']) <= 60 && validate_username($data['login']), 'Enter a valid WordPress user ID.');
        if ($linkedId) {
            self::requireRule($account && $account->roles && !array_diff($account->roles,array_keys(self::EMPLOYEE_ROLES))
                && !is_super_admin($linkedId) && $account->user_login === $data['login'],
                'The linked account must keep its existing User ID and a Frontier role.');
        }
        $role = self::input('frontier_employee_role');
        self::requireRule(in_array($role,['admin','employee'],true),'Select a valid Frontier role.');
        $status = self::input('frontier_employee_status');
        self::requireRule(in_array($status,['active','inactive'],true),'Select a valid employee status.');
        $requested = $_POST['frontier_employee_views'] ?? [];
        self::requireRule(is_array($requested),'Select valid Frontier views.');
        $allowed = array_diff_key(self::views(),['home'=>true,'stock'=>true]);
        $views = [];
        foreach ($requested as $view) {
            self::requireRule(is_string($view) && isset($allowed[$view]),'Select valid Frontier views.');
            $views[$view] = true;
        }
        $input = $_POST['password'] ?? '';
        $password = is_string($input) ? wp_unslash($input) : '';
        self::requireRule(($linkedId && $password === '') || ($password !== '' && strlen($password) <= 4096
            && trim($password) === $password), 'Enter a password without spaces at either end (maximum 4096 bytes).');
        $user = ['user_login'=>$data['login'], 'user_email'=>$data['email'],
            'display_name'=>$data['name']];
        if (!$linkedId) {
            $user['role'] = 'frontier_requester';
        }
        if ($password !== '') {
            $user['user_pass'] = $password;
        }
        $id = $linkedId ? wp_update_user(['ID'=>$linkedId] + $user) : wp_insert_user($user);
        self::requireRule(!is_wp_error($id), is_wp_error($id) ? $id->get_error_message() : '');
        $access = ['role'=>$role,'views'=>$role === 'employee' ? array_keys($views) : [],'status'=>$status];
        if (self::assignedViewAccess((int)$id) !== $access && update_user_meta((int)$id,'frontier_view_access',$access) === false) {
            throw new RuntimeException('Frontier view access could not be saved.');
        }
        return (int)$id;
    }

    private static function templateControls($kind,$addUrl) {
        if (isset($_GET['imported'])) {
            echo '<p role="status">'.esc_html(absint($_GET['imported']).' '.$kind.' records added.').'</p>';
        }
        $download = add_query_arg(['action'=>'frontier_template','kind'=>$kind],admin_url('admin-post.php'));
        echo '<div class="frontier-master-toolbar">';
        echo '<a class="button frontier-add" href="'.esc_url($addUrl).'">Add</a>';
        echo '<a class="button frontier-download" href="'.esc_url(wp_nonce_url($download,'frontier_template_'.$kind)).'">Download Template</a>';
        if ($kind === 'supplier') {
            $export = add_query_arg('export',1,$download);
            echo ' <a class="button frontier-download" href="'.esc_url(wp_nonce_url($export,'frontier_supplier_export')).'">Download Data</a>';
        }
        echo ' <a class="button frontier-upload" href="'.esc_url(add_query_arg('upload',1,self::url($kind))).'">Upload Template</a></div>';
    }

    private static function uploadTemplateForm($kind) {
        $operation = ['employee'=>'import','item'=>'import_items','supplier'=>'import_suppliers'][$kind];
        self::formStart($kind,$operation);
        echo '<p><label>'.self::requiredLabel('CSV file').' <input type="file" name="import_template" accept=".csv,text/csv" required></label></p>';
        self::submitButton('Import',true);
        echo ' <a class="button" href="'.esc_url(self::url($kind)).'">Cancel</a></form>';
    }

    public static function downloadTemplate() {
        $kind = isset($_GET['kind']) && is_string($_GET['kind']) ? sanitize_key(wp_unslash($_GET['kind'])) : '';
        if (!isset(self::IMPORT_TEMPLATES[$kind]) || !self::canView($kind)) {
            wp_die('Access denied.', '', ['response'=>403]);
        }
        $export = $kind === 'supplier' && isset($_GET['export']);
        check_admin_referer($export ? 'frontier_supplier_export' : 'frontier_template_'.$kind);
        $records = $export ? self::rows() : [];
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="frontier-'.$kind.($export ? '-data' : '-template').'.csv"');
        $output = fopen('php://output','wb');
        fwrite($output,"\xEF\xBB\xBF");
        $headers = self::IMPORT_TEMPLATES[$kind];
        if ($kind === 'supplier' && !$export) {
            unset($headers[0],$headers[2]);
        }
        fputcsv($output,array_values($headers),',','"','');
        foreach ($records as $row) {
            if ($row['kind'] !== 'supplier') {
                continue;
            }
            $data = $row['data'];
            $values = [];
            foreach (array_keys(self::FIELDS['supplier']) as $field) {
                $values[] = (string)($data[$field] ?? '');
            }
            array_splice($values,2,0,[$values[0].' - '.$values[1]]);
            $values = array_map(static function ($value) {
                return preg_match('/^\s*[=+\-@]/u',$value) ? "'".$value : $value;
            },$values);
            fputcsv($output,$values,',','"','');
        }
        fclose($output);
        exit;
    }

    private static function readImportTemplate($kind,$limit) {
        $file = $_FILES['import_template'] ?? [];
        $path = $file['tmp_name'] ?? '';
        self::requireRule(($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
            && is_string($path) && is_uploaded_file($path) && ($file['size'] ?? 0) <= 500000
            && is_string($file['name'] ?? null) && strtolower(pathinfo($file['name'],PATHINFO_EXTENSION)) === 'csv',
            'Upload a CSV template up to 500 KB.');
        $contents = file_get_contents($path);
        self::requireRule(is_string($contents),'Could not read the CSV file.');
        $bom = substr($contents,0,2);
        if ($bom === "\xFF\xFE" || $bom === "\xFE\xFF") {
            $contents = self::convertCsvEncoding(substr($contents,2),$bom === "\xFF\xFE" ? 'UTF-16LE' : 'UTF-16BE');
        } elseif (substr($contents,0,3) === "\xEF\xBB\xBF") {
            $contents = substr($contents,3);
        } elseif (!preg_match('//u',$contents)) {
            $contents = self::convertCsvEncoding($contents,'Windows-1252');
        }
        self::requireRule(preg_match('//u',$contents),'Save the file as CSV UTF-8 and upload it again.');

        // Parse after decoding so quoted fields and commas use standard CSV rules.
        $stream = fopen('php://temp','w+b');
        self::requireRule($stream !== false,'Could not open the CSV file.');
        try {
            self::requireRule(fwrite($stream,$contents) === strlen($contents) && rewind($stream),'Could not read the CSV file.');
            $headers = fgetcsv($stream,0,',','"','');
            $expected = self::IMPORT_TEMPLATES[$kind];
            $headers = $headers ? array_map('trim',$headers) : [];
            if ($kind === 'supplier') {
                $headers = array_map(static function ($header) {
                    $key = strtolower($header);
                    return in_array($key,['supplier name','suppliers name','name'],true) ? 'supplier names' : $key;
                },$headers);
                $expectedKeys = array_map('strtolower',$expected);
                self::requireRule(in_array('supplier names',$headers,true)
                    && count(array_unique($headers)) === count($headers)
                    && !array_diff($headers,$expectedKeys),
                    'Include Supplier Name and use the template headers for any optional columns.');
            } else {
                self::requireRule($headers === $expected,
                    'Use the template headers in this order: '.implode(', ',$expected).'.');
            }
            $rows = [];
            $line = 1;
            while (($row = fgetcsv($stream,0,',','"','')) !== false) {
                $line++;
                $row = array_map(static function ($value) use ($kind) {
                    if ($kind === 'supplier') {
                        return self::supplierText(preg_replace("/^'(?=\s*[=+@-])/u",'',(string)$value) ?? '');
                    }
                    if ($kind === 'item') {
                        return trim(preg_replace('/[\x00-\x1F\x7F]+/u',' ',(string)$value));
                    }
                    return trim(sanitize_text_field((string)$value));
                },$row);
                if ($row === [''] || $row === array_fill(0,count($headers),'')) {
                    continue;
                }
                if ($kind === 'supplier') {
                    self::requireRule(count($row) <= count($headers),'Check the column count on CSV row '.$line.'.');
                    $byHeader = array_combine($headers,array_pad($row,count($headers),''));
                    $row = array_map(static function ($key) use ($byHeader) {
                        return $byHeader[$key] ?? '';
                    },$expectedKeys);
                    $row[0] = '';
                    $row[2] = '';
                } else {
                    self::requireRule(count($row) === count($headers),'Check the column count on CSV row '.$line.'.');
                }
                foreach ($row as $index=>$value) {
                    $label = $expected[$index];
                    $optional = $kind === 'supplier' && $index !== 1;
                    $length = $kind === 'supplier' ? (self::SUPPLIER_TEXT_LIMITS[strtolower($label)] ?? 200) : 200;
                    self::requireRule($optional || $value !== '',$label.' is required on CSV row '.$line.'.');
                    self::requireRule(strlen($value) <= $length,$label.' on CSV row '.$line.' exceeds '.$length.' bytes.');
                }
                $rows[] = $row;
                self::requireRule(count($rows) <= $limit,'Upload up to '.$limit.' records.');
            }
            self::requireRule($rows,'Add at least one record below the header.');
            return $rows;
        } finally {
            fclose($stream);
        }
    }

    private static function convertCsvEncoding($contents,$encoding) {
        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($contents,'UTF-8',$encoding);
        }
        $converted = function_exists('iconv') ? @iconv($encoding,'UTF-8',$contents) : false;
        self::requireRule($converted !== false,'Save the file as CSV UTF-8 and upload it again.');
        return $converted;
    }

    private static function importEmployees($rows) {
        global $wpdb;
        $names = self::readImportTemplate('employee',250);
        $usedNames = [];
        $usedLogins = [];
        foreach ($rows as $row) {
            if ($row['kind'] === 'employee') {
                $name = preg_replace('/\s+/u',' ',trim($row['data']['name']));
                $usedNames[strtolower($name)] = true;
                $usedLogins[strtolower($row['data']['login'] ?? '')] = true;
            }
        }
        $count = 0;
        foreach ($names as [$value]) {
            $name = preg_replace('/[\s\p{Z}]+/u',' ',trim($value)) ?? '';
            $nameKey = strtolower($name);
            if (isset($usedNames[$nameKey])) {
                continue;
            }
            $base = strtolower(sanitize_user(explode(' ',$name,2)[0],true));
            self::requireRule(strlen($name) <= 200 && $base !== '' && strlen($base) <= 55,'Check the employee name '.$name.'.');
            $login = $base;
            for ($suffix = 2; isset($usedLogins[$login]) || username_exists($login); $suffix++) {
                $login = $base.$suffix;
            }
            $result = $wpdb->insert(self::table(),[
                'kind'=>'employee', 'status'=>'pending_setup',
                'details'=>wp_json_encode(['name'=>$name,'login'=>$login]),
            ]);
            if ($result !== 1) {
                throw new RuntimeException('Employee could not be imported.');
            }
            $count++;
            $usedNames[$nameKey] = true;
            $usedLogins[$login] = true;
        }
        return $count;
    }

    private static function importItems($records) {
        global $wpdb;
        $items = self::readImportTemplate('item',1000);
        $lookup = ['category'=>[], 'item_type'=>[]];
        $existing = [];
        foreach ($records as $record) {
            $kind = $record['kind'];
            $data = $record['data'];
            if (isset($lookup[$kind])) {
                $lookup[$kind][strtolower(trim($data['name']))] = (int)$record['id'];
            } elseif ($kind === 'item') {
                $existing[$data['category'] ?? 0][$data['item_type'] ?? 0][strtolower(trim($data['name']))] = true;
            }
        }
        $added = 0;
        foreach ($items as [$name,$category,$type]) {
            foreach (['category'=>$category,'item_type'=>$type] as $kind=>$label) {
                $key = strtolower($label);
                if (!isset($lookup[$kind][$key])) {
                    self::insertImportedRecord($kind,['name'=>$label]);
                    $lookup[$kind][$key] = (int)$wpdb->insert_id;
                }
            }
            $categoryId = $lookup['category'][strtolower($category)];
            $typeId = $lookup['item_type'][strtolower($type)];
            $nameKey = strtolower($name);
            if (isset($existing[$categoryId][$typeId][$nameKey])) {
                continue;
            }
            self::insertImportedRecord('item',['name'=>$name,'category'=>$categoryId,'item_type'=>$typeId]);
            $existing[$categoryId][$typeId][$nameKey] = true;
            $added++;
        }
        return $added;
    }

    private static function importSuppliers($records) {
        $rows = self::readImportTemplate('supplier',5000);
        $last = self::nextNumber('supplier',self::lastMasterCode($records,'supplier'))-1;
        $fields = array_values(array_diff(array_keys(self::FIELDS['supplier']),['ref']));
        $signature = static function ($data) use ($fields) {
            return wp_json_encode(array_map(static function ($field) use ($data) {
                return strtolower(trim($data[$field] ?? ''));
            },$fields));
        };
        $used = [];
        foreach ($records as $record) {
            if ($record['kind'] === 'supplier') {
                $used[$signature($record['data'])] = true;
            }
        }
        $count = 0;
        foreach ($rows as $row) {
            array_splice($row,2,1);
            $data = array_combine(array_keys(self::FIELDS['supplier']),$row);
            $key = $signature($data);
            if (isset($used[$key])) {
                continue;
            }
            $data['ref'] = self::formatMasterCode(++$last);
            self::insertImportedRecord('supplier',$data);
            $used[$key] = true;
            $count++;
        }
        return $count;
    }

    private static function insertImportedRecord($kind,$data) {
        global $wpdb;
        if ($wpdb->insert(self::table(),[
            'kind'=>$kind, 'status'=>'recorded', 'details'=>wp_json_encode($data),
        ]) !== 1) {
            throw new RuntimeException('Imported record could not be saved.');
        }
    }

    private static function boqGroup($row) {
        $number = $row['data']['boq_number'] ?? 0;
        return $number ? 'number-'.$number : 'legacy-'.$row['id'];
    }

    private static function boqLines($rows,$id) {
        $boq = $rows[$id] ?? null;
        self::requireRule($boq && $boq['kind'] === 'boq','BOQ not found.');
        $key = self::boqGroup($boq);
        return array_filter(array_reverse($rows,true),static function ($row) use ($key) {
            return $row['kind'] === 'boq' && self::boqGroup($row) === $key;
        });
    }

    private static function boqReferences($rows) {
        $used = [];
        foreach ($rows as $row) {
            if ($row['kind'] === 'indent' && !empty($row['data']['boq'])) {
                $used[(int)$row['data']['boq']] = true;
            }
        }
        return $used;
    }

    private static function boqRegister($rows,$itemsOnly) {
        $used = $itemsOnly ? [] : self::boqReferences($rows);
        $indents = [];
        $approvedOrders = [];
        foreach ($rows as $row) {
            $data = $row['data'];
            if ($row['kind'] === 'indent' && in_array($row['status'],['pending','approved'],true)) {
                $id = (int)$data['boq'];
                $indents[$id] = ($indents[$id] ?? 0) + $data['qty'];
            } elseif ($row['kind'] === 'po' && $row['status'] === 'issued') {
                $approvedOrders[$row['id']] = (float)$data['qty'];
            }
        }
        $receipts = [];
        foreach ($rows as $row) {
            $po = $row['data']['po'] ?? 0;
            if (!isset($approvedOrders[$po])) {
                continue;
            }
            if ($row['kind'] === 'cancel') {
                $approvedOrders[$po] -= $row['data']['qty'];
            } elseif ($row['kind'] === 'grn' && $row['status'] === 'approved') {
                $receipts[$po] = ($receipts[$po] ?? 0) + $row['data']['qty'];
            }
        }
        $ordered = [];
        $received = [];
        foreach ($approvedOrders as $po=>$quantity) {
            $indent = $rows[$rows[$po]['data']['indent']] ?? null;
            if (!$indent || $indent['kind'] !== 'indent') {
                continue;
            }
            $id = (int)$indent['data']['boq'];
            $ordered[$id] = ($ordered[$id] ?? 0) + max(0,$quantity);
            $received[$id] = ($received[$id] ?? 0) + ($receipts[$po] ?? 0);
        }
        $groups = [];
        $items = [];
        foreach ($rows as $row) {
            if ($row['kind'] !== 'boq') {
                continue;
            }
            $data = $row['data'];
            $id = (int)$row['id'];
            $key = self::boqGroup($row);
            $date = !empty($data['boq_date']) ? implode('/',array_reverse(explode('-',$data['boq_date']))) : '';
            $timestamp = !empty($data['boq_timestamp']) ? mysql2date('d/m/Y H:i:s',$data['boq_timestamp'],false) : '';
            $header = [
                'date'=>$date,'number'=>$data['boq_number'] ?? '#'.$id,
                'site'=>self::siteNumberWithName($rows[$data['site'] ?? 0]['data'] ?? []),
                'prepared'=>$data['prepared_name'] ?? '', 'timestamp'=>$timestamp,
            ];
            $quantity = (float)$data['qty'];
            $indented = $indents[$id] ?? 0;
            $order = $ordered[$id] ?? 0;
            $receipt = $received[$id] ?? 0;
            $item = $rows[$data['item_id'] ?? 0]['data'] ?? [];
            $items[$key][] = $header + [
                'line'=>$data['line_number'] ?? 1,'item'=>$data['name'] ?? '',
                'qty'=>$quantity,'unit'=>$data['unit'] ?? '',
                'indented'=>$indented,'pending_indent'=>max(0,$quantity-$indented),
                'ordered'=>$order,'pending_order'=>max(0,$indented-$order),
                'category'=>$rows[$item['category'] ?? 0]['data']['name'] ?? ($data['item_category'] ?? ''),
                'received'=>$receipt,'pending_receive'=>max(0,$order-$receipt),
            ];
            if (!isset($groups[$key])) {
                $groups[$key] = $header + ['pdf'=>$id,'qty'=>0,'indented'=>0,'pending_indent'=>0,'used'=>false];
            }
            $groups[$key]['used'] = $groups[$key]['used'] || isset($used[$id]);
            $groups[$key]['qty'] += $quantity;
            $groups[$key]['indented'] += $indented;
            $groups[$key]['pending_indent'] += max(0,$quantity-$indented);
        }
        $columns = $itemsOnly ? [
            'timestamp'=>'Timestamp','number'=>'BOQ No.','date'=>'BOQ Date','site'=>'Site Number with Name','prepared'=>'BOQ Prepared By',
            'line'=>'Sl No.','item'=>'Item Name','qty'=>'Quantity Required','unit'=>'Units','indented'=>'Quantity Already Indented',
            'pending_indent'=>'Quantity Pending to be Indented','ordered'=>'Quantity Ordered','pending_order'=>'Pending to Order',
            'category'=>'Item Category','received'=>'Quantity Received','pending_receive'=>'Pending to Receive',
        ] : [
            'date'=>'BOQ Date','number'=>'BOQ No.','site'=>'Site Number with Name','prepared'=>'Prepared By','pdf'=>'Pdf',
            'timestamp'=>'Timestamp','qty'=>'Total Quantity','indented'=>'Quantity Indented','pending_indent'=>'Pending to Indent',
        ];
        if (!$itemsOnly && self::can('boq')) {
            $columns['actions'] = 'Actions';
        }
        if ($itemsOnly) {
            $listing = [];
            foreach ($items as $lines) {
                foreach (array_reverse($lines) as $line) {
                    $listing[] = $line;
                }
            }
        } else {
            $listing = $groups;
        }
        [$pageRows,$page,$pages,$total] = self::pagedRows($listing,array_diff_key($columns,['actions'=>true]),static function ($row) {
            if (isset($row['pdf'])) {
                $row['pdf'] = self::exportLink('PDF','frontier_boq_print',$row['pdf']);
            }
            return $row;
        });
        echo '<div class="frontier-table" tabindex="0" role="region" aria-label="BOQ records"><table class="widefat striped"><thead><tr>';
        foreach ($columns as $label) {
            echo '<th scope="col">'.esc_html($label).'</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($pageRows as $record) {
            echo '<tr>';
            foreach ($columns as $key=>$label) {
                echo '<td>';
                if ($key === 'pdf') {
                    $id = $record['pdf'];
                    $url = add_query_arg(['action'=>'frontier_boq_print','id'=>$id],admin_url('admin-post.php'));
                    echo '<a class="button frontier-download" href="'.esc_url(wp_nonce_url($url,'frontier_boq_print_'.$id)).'">PDF</a>';
                } elseif ($key === 'actions') {
                    $id = $record['pdf'];
                    echo '<div class="frontier-row-actions">';
                    self::editAction('boq',$id,'Edit BOQ');
                    if (!$record['used']) {
                        self::deleteAction($rows[$id],'delete_boq','Delete BOQ','Delete this BOQ?');
                    }
                    echo '</div>';
                } else {
                    $value = $record[$key];
                    echo esc_html(is_float($value) ? (string)round($value,3) : (string)$value);
                }
                echo '</td>';
            }
            echo '</tr>';
        }
        if (!$total) {
            echo '<tr><td colspan="'.count($columns).'">No BOQs yet.</td></tr>';
        }
        echo '</tbody></table></div>';
        self::pagination($page,$pages,$total);
    }

    public static function printBoq() {
        $id = absint($_GET['id'] ?? 0);
        if (!self::canView('boq')) {
            wp_die('Access denied.','Frontier',['response'=>403]);
        }
        check_admin_referer('frontier_boq_print_'.$id);
        $rows = self::rows();
        $boq = $rows[$id] ?? null;
        if (!$boq || $boq['kind'] !== 'boq') {
            wp_die('BOQ not found.','Frontier',['response'=>404]);
        }
        $header = $boq['data'];
        $number = $header['boq_number'] ?? '#'.$id;
        $site = self::siteNumberWithName($rows[$header['site'] ?? 0]['data'] ?? []);
        $date = !empty($header['boq_date']) ? implode('/',array_reverse(explode('-',$header['boq_date']))) : '';
        $lines = [];
        foreach (self::boqLines($rows,$id) as $row) {
            $data = $row['data'];
            $lines[] = [$data['line_number'] ?? 1,$data['name'] ?? '',$data['qty'] ?? '',$data['unit'] ?? ''];
        }
        $pdf = Frontier_Boq_Pdf::build($number,$site,$date,$header['prepared_name'] ?? '',$lines);
        $fileNumber = absint($header['boq_number'] ?? $id);
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="boq-'.$fileNumber.'.pdf"');
        header('Content-Length: '.strlen($pdf));
        echo $pdf;
        exit;
    }

    private static function boqForm($rows,$lines=[]) {
        $items = [];
        $first = reset($lines);
        $header = $first['data'] ?? [];
        $used = $lines ? array_intersect_key(self::boqReferences($rows),$lines) : [];
        $initialRows = [];
        foreach ($lines as $id=>$line) {
            $data = $line['data'];
            $initialRows[] = ['id'=>(int)$id,'item'=>(int)($data['item_id'] ?? 0),'name'=>$data['name'],
                'qty'=>$data['qty'],'unit'=>$data['unit'],'locked'=>isset($used[$id])];
        }
        foreach ($rows as $row) {
            $data = $row['data'];
            if ($row['kind'] === 'item') {
                $items[] = ['id'=>(int)$row['id'],'name'=>$data['name'],
                    'category'=>$rows[$data['category'] ?? 0]['data']['name'] ?? '',
                    'type'=>$rows[$data['item_type'] ?? 0]['data']['name'] ?? ''];
            }
        }
        $endpoint = add_query_arg('action','frontier_boq_excel',admin_url('admin-post.php'));
        $template = wp_nonce_url(add_query_arg('download',1,$endpoint),'frontier_boq_excel');
        $upload = add_query_arg('_wpnonce',wp_create_nonce('frontier_boq_excel'),$endpoint);
        self::formStart('boq',$first ? 'edit_boq' : 'create_boq',$first['id'] ?? 0);
        echo '<input type="hidden" name="boq_lines"><input type="hidden" name="boq_token" value="'.esc_attr(wp_generate_uuid4()).'">';
        if ($first) {
            echo '<input type="hidden" name="boq_revision" value="'.esc_attr(hash('sha256',wp_json_encode($lines))).'">';
        }
        echo '<div class="frontier-fields">';
        self::field($rows,'boq','due','BOQ Date',$header['boq_date'] ?? current_time('Y-m-d'));
        if ($used) {
            $siteId = $header['site'];
            echo '<input type="hidden" name="site" value="'.esc_attr($siteId).'"><p><label>Site Number with Name <input readonly value="';
            echo esc_attr(self::siteNumberWithName($rows[$siteId]['data'] ?? [])).'"></label></p>';
        } else {
            self::field($rows,'boq','site','Site Number with Name',$header['site'] ?? '');
        }
        self::field($rows,'boq','employee','Prepared By',$header['prepared_by'] ?? '');
        echo '</div>';
        echo '<h2>BOQ Items</h2><div class="frontier-master-toolbar">';
        echo '<button type="button" class="button frontier-boq-add">Add Row</button>';
        echo '<a class="button frontier-download" href="'.esc_url($template).'">Download Excel Template</a>';
        echo '<label class="button frontier-upload">Upload Excel<input class="frontier-boq-file" type="file" accept=".xlsx" data-endpoint="'.esc_url($upload).'"></label></div>';
        echo '<p class="frontier-boq-message" role="status"></p>';
        echo '<div class="frontier-table"><table class="widefat striped"><thead><tr>';
        foreach (['Sl. No.','Item Name','Quantity','Unit','Item Master Check','Action'] as $label) {
            echo '<th scope="col">'.esc_html($label).'</th>';
        }
        echo '</tr></thead><tbody></tbody></table></div>';
        echo '<datalist id="frontier-boq-items"></datalist>';
        echo '<script type="application/json" class="frontier-boq-data">'.wp_json_encode(['items'=>$items,'lines'=>$initialRows],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).'</script>';
        echo '<p><button type="submit" class="button button-primary frontier-boq-save" disabled>Save BOQ</button> ';
        echo '<a class="button" href="'.esc_url(self::url('boq')).'">Cancel</a></p>';
        echo '<noscript><p>Enable JavaScript to add or upload BOQ items.</p></noscript></form>';
    }

    public static function boqExcel() {
        if (!self::canView('boq_form') || !self::can('boq')) {
            wp_die('Access denied.','Frontier',['response'=>403]);
        }
        check_admin_referer('frontier_boq_excel');
        if (isset($_GET['download'])) {
            if (!Frontier_Boq_Workbook::available()) {
                wp_die('Excel support requires PHP ZipArchive and SimpleXML.','Frontier',['response'=>503]);
            }
            $path = tempnam(sys_get_temp_dir(),'frontier-boq-');
            if (!$path || !Frontier_Boq_Workbook::template($path)) {
                if ($path) {
                    unlink($path);
                }
                wp_die('Could not create the Excel template.','Frontier',['response'=>500]);
            }
            nocache_headers();
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="frontier-boq-template.xlsx"');
            readfile($path);
            unlink($path);
            exit;
        }
        try {
            $file = $_FILES['workbook'] ?? [];
            $path = $file['tmp_name'] ?? '';
            self::requireRule(($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_string($path)
                && is_uploaded_file($path) && ($file['size'] ?? 0) <= 4000000 && is_string($file['name'] ?? null)
                && strtolower(pathinfo($file['name'],PATHINFO_EXTENSION)) === 'xlsx','Upload an .xlsx workbook up to 4 MB.');
            wp_send_json_success(['rows'=>Frontier_Boq_Workbook::read($path)]);
        } catch (DomainException $error) {
            wp_send_json_error(['message'=>$error->getMessage()],400);
        }
    }

    private static function saveBoq($rows,$id=0) {
        global $wpdb;
        $existing = $id ? self::boqLines($rows,$id) : [];
        self::requireRule(!$id || hash_equals(hash('sha256',wp_json_encode($existing)),self::input('boq_revision')),
            'This BOQ has changed. Reopen it before editing.');
        $first = reset($existing);
        $previous = $first['data'] ?? [];
        $used = $existing ? array_intersect_key(self::boqReferences($rows),$existing) : [];
        $site = $rows[absint(self::input('site'))] ?? null;
        $employee = $rows[absint(self::input('employee'))] ?? null;
        $dateText = self::input('due');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d',$dateText);
        self::requireRule($site && $site['kind'] === 'site','Select a site.');
        self::requireRule($employee && $employee['kind'] === 'employee','Select Prepared By.');
        self::requireRule($date && $date->format('Y-m-d') === $dateText,'Enter a valid BOQ date.');
        $raw = $_POST['boq_lines'] ?? '';
        $lines = is_string($raw) && strlen($raw) <= 200000 ? json_decode(wp_unslash($raw),true) : null;
        self::requireRule(is_array($lines) && count($lines) > 0 && count($lines) <= 500,'Add between 1 and 500 BOQ item rows.');
        $token = self::input('boq_token');
        self::requireRule(wp_is_uuid($token,4),'Reopen the BOQ form and try again.');
        self::requireRule(!$used || (int)$site['id'] === (int)$previous['site'],'The site cannot change once the BOQ is used in an indent.');
        $last = (int)get_option('frontier_last_deleted_boq',0);
        foreach ($rows as $row) {
            if ($row['kind'] !== 'boq') {
                continue;
            }
            if (!$id && ($row['data']['boq_token'] ?? '') === $token) {
                return;
            }
            $last = max($last,(int)($row['data']['boq_number'] ?? 0));
        }
        $header = ['site'=>(int)$site['id'],'prepared_by'=>(int)$employee['id'],'prepared_name'=>$employee['data']['name'],
            'group_id'=>$site['data']['telegram'] ?? '', 'boq_date'=>$dateText,'boq_number'=>$previous['boq_number'] ?? self::nextNumber('boq',$last),
            'boq_token'=>$previous['boq_token'] ?? $token,'boq_timestamp'=>$id ? ($previous['boq_timestamp'] ?? '') : current_time('mysql')];
        $seen = [];
        foreach (array_values($lines) as $index=>$line) {
            $number = $index+1;
            self::requireRule(is_array($line),'Check BOQ row '.$number.'.');
            $lineId = $line['id'] ?? 0;
            self::requireRule(is_int($lineId) && (!$lineId || (isset($existing[$lineId]) && !isset($seen[$lineId]))),
                'BOQ item line is invalid or has already changed.');
            $old = $existing[$lineId]['data'] ?? [];
            $itemId = $line['item'] ?? null;
            $item = is_int($itemId) ? ($rows[$itemId] ?? null) : null;
            $qty = is_string($line['qty'] ?? null) ? trim($line['qty']) : '';
            $unit = is_string($line['unit'] ?? null) ? sanitize_text_field($line['unit']) : '';
            $sameSavedItem = $lineId && $itemId === (int)($old['item_id'] ?? 0);
            self::requireRule($sameSavedItem || ($item && $item['kind'] === 'item'),'Choose an Item Master entry on row '.$number.'.');
            self::requireRule(preg_match('/^\d{1,10}(?:\.\d{1,3})?$/D',$qty) && (float)$qty > 0,'Enter a valid quantity on row '.$number.'.');
            self::requireRule($unit !== '' && strlen($unit) <= 200,'Enter a unit on row '.$number.'.');
            if (isset($used[$lineId])) {
                self::requireRule($sameSavedItem && (float)$qty === (float)$old['qty'] && $unit === $old['unit'],
                    'An item already used in an indent cannot be changed.');
            }
            $data = $header + [
                'item_id'=>$itemId,'name'=>$sameSavedItem ? $old['name'] : $item['data']['name'],
                'qty'=>(float)$qty,'unit'=>$unit,'section'=>$old['section'] ?? '', 'line_number'=>$number,
                'item_category'=>$sameSavedItem ? ($old['item_category'] ?? '') : ($rows[$item['data']['category'] ?? 0]['data']['name'] ?? ''),
            ];
            if ($lineId) {
                $seen[$lineId] = true;
                if ($wpdb->update(self::table(),['details'=>wp_json_encode($data + $old)],['id'=>$lineId]) === false) {
                    throw new RuntimeException('BOQ line could not be saved.');
                }
            } else {
                self::insertImportedRecord('boq',$data);
            }
        }
        self::requireRule(!array_diff_key($used,$seen),'An item already used in an indent cannot be removed.');
        foreach (array_diff_key($existing,$seen) as $lineId=>$line) {
            if ($wpdb->delete(self::table(),['id'=>$lineId]) !== 1) {
                throw new RuntimeException('BOQ line could not be removed.');
            }
        }
    }

    private static function indentGroup($row) {
        $number = (int)($row['data']['indent_number'] ?? 0);
        return $number ? 'number-'.$number : 'legacy-'.$row['id'];
    }

    private static function indentGroupFilter() {
        if (self::$screen !== 'indent_items' || !is_string($_GET['indent_group'] ?? null)) {
            return '';
        }
        return sanitize_key(wp_unslash($_GET['indent_group']));
    }

    private static function indentItemColumns() {
        return [
            'entered'=>'Indent Timestamp','number'=>'Indent No.','date'=>'Indent Date','site'=>'Site Number with Name',
            'requested'=>'Requested By','sl'=>'Sl No.','item'=>'Item','unit'=>'Units','boq_qty'=>'Quantity as per BOQ',
            'already'=>'Quantity Already Indented','qty'=>'Quantity Required Now','excess'=>'Whether Excess Indented',
            'last_supplier'=>'Last Supplier','last_rate'=>'Last Rate',
            'supplier_1'=>'Supplier1','rate_1'=>'Rate1','quotation_1'=>'Quotation1',
            'supplier_2'=>'Supplier2','rate_2'=>'Rate2','quotation_2'=>'Quotation2',
            'supplier_3'=>'Supplier3','rate_3'=>'Rate3','quotation_3'=>'Quotation3',
            'final_supplier'=>'Supplier Finalized','final_rate'=>'Rate Finalized',
            'quote_at'=>'Quotation Timestamp','compared_by'=>'Compared By',
            'ordered'=>'Quantity Ordered','pending_order'=>'Pending to Order',
            'type'=>'Item Type','approval'=>'Approval Status','received'=>'Quantity Received','pending_receive'=>'Pending to Receive',
            'cancel_date'=>'Cancel Date',
            'cancelled'=>'Indent Item Canceled','cancel_reason'=>'Cancel Reason','cancel_by'=>'Cancel By',
        ];
    }

    private static function lastIssuedPurchase($rows,$boq,$excludeIndent) {
        $itemId = (int)($boq['item_id'] ?? 0);
        $name = trim($boq['name'] ?? '');
        $latest = null;
        foreach ($rows as $order) {
            if ($order['kind'] !== 'po' || $order['status'] !== 'issued') {
                continue;
            }
            $indentId = (int)$order['data']['indent'];
            if ($indentId === $excludeIndent) {
                continue;
            }
            $previousBoq = $rows[$rows[$indentId]['data']['boq'] ?? 0]['data'] ?? [];
            $previousItem = (int)($previousBoq['item_id'] ?? 0);
            $sameItem = $itemId && $previousItem ? $itemId === $previousItem
                : $name !== '' && strcasecmp(trim($previousBoq['name'] ?? ''),$name) === 0;
            if (!$sameItem) {
                continue;
            }
            $date = $order['data']['issued_at'] ?? '';
            $latestDate = $latest['data']['issued_at'] ?? '';
            if (!$latest || $date > $latestDate || ($date === $latestDate && (int)$order['id'] > (int)$latest['id'])) {
                $latest = $order;
            }
        }
        return $latest['data'] ?? [];
    }

    private static function indentItemValues($row,$rows) {
        $data = $row['data'];
        $id = (int)$row['id'];
        $boqId = (int)$data['boq'];
        $boq = $rows[$boqId]['data'] ?? [];
        $itemId = (int)($boq['item_id'] ?? 0);
        $item = $rows[$itemId]['data'] ?? [];
        $site = $rows[$data['site'] ?? ($boq['site'] ?? 0)]['data'] ?? [];
        $timestamp = static function ($value) {
            return $value ? mysql2date('d/m/Y H:i:s',$value,false) : '';
        };
        $values = [
            'entered'=>$timestamp($data['indent_timestamp'] ?? ''),'number'=>$data['indent_number'] ?? '#'.$id,
            'date'=>self::indentDisplayDate($data['indent_date'] ?? ''),'site'=>self::siteNumberWithName($site),
            'requested'=>$data['requested_name'] ?? '', 'sl'=>$data['line_number'] ?? 1,
            'item'=>$boq['name'] ?? '', 'unit'=>$data['unit'] ?? ($boq['unit'] ?? ''),
            'boq_qty'=>$boq['qty'] ?? '', 'qty'=>$data['qty'],
            'type'=>$rows[$item['item_type'] ?? 0]['data']['name'] ?? '',
            'approval'=>ucwords(str_replace('_',' ',$row['status'])),
            'cancel_date'=>self::indentDisplayDate($data['cancel_date'] ?? ''),
            'cancelled'=>$data['cancelled_qty'] ?? '',
            'cancel_reason'=>$data['cancel_reason'] ?? '', 'cancel_by'=>$data['cancel_by'] ?? '',
        ];
        $orders = [];
        $quotes = [];
        $past = self::lastIssuedPurchase($rows,$boq,$id);
        $already = 0;
        $selected = null;
        foreach ($rows as $record) {
            $recordId = (int)$record['id'];
            $recordData = $record['data'];
            if ($record['kind'] === 'quote' && (int)$recordData['indent'] === $id) {
                $quotes[] = $record;
                if ($record['status'] === 'selected'
                    && (!$selected || ($recordData['compared_at'] ?? '') > ($selected['data']['compared_at'] ?? ''))) {
                    $selected = $record;
                }
            } elseif ($record['kind'] === 'po' && $record['status'] === 'issued') {
                $indentId = (int)$recordData['indent'];
                if ($indentId === $id) {
                    $orders[$recordId] = $recordData['qty'];
                }
            } elseif ($record['kind'] === 'indent' && $recordId < $id && (int)$recordData['boq'] === $boqId
                && in_array($record['status'],['pending','approved'],true)) {
                $already += $recordData['qty'];
            }
        }
        $received = 0;
        foreach ($rows as $record) {
            $po = $record['data']['po'] ?? 0;
            if (!isset($orders[$po])) {
                continue;
            }
            if ($record['kind'] === 'cancel') {
                $orders[$po] -= $record['data']['qty'];
            } elseif ($record['kind'] === 'grn' && in_array($record['status'],['pending','approved'],true)) {
                $received += $record['data']['qty'];
            }
        }
        $ordered = round(array_sum($orders),3);
        $already = $data['already_indented'] ?? round($already,3);
        $excess = $data['excess'] ?? round($data['qty'] - max(0,($boq['qty'] ?? 0)-$already),3);
        $values += [
            'already'=>$already,'excess'=>$excess > 0 ? 'Yes' : 'No',
            'ordered'=>$ordered,'pending_order'=>$row['status'] === 'approved' ? max(0,round($data['qty']-$ordered,3)) : 0,
            'received'=>round($received,3),'pending_receive'=>max(0,round($ordered-$received,3)),
            'last_supplier'=>$past ? self::supplierDisplay($past,$rows) : '', 'last_rate'=>$past['rate'] ?? '',
        ];
        $latestQuote = '';
        $supplierSlots = [];
        foreach ($quotes as $quote) {
            $quoteData = $quote['data'];
            $name = self::supplierDisplay($quoteData,$rows);
            $latestQuote = max($latestQuote,$quoteData['quote_timestamp'] ?? '');
            $key = !empty($quoteData['supplier']) ? 'id-'.$quoteData['supplier'] : strtolower($name);
            if (!isset($supplierSlots[$key]) && count($supplierSlots) < 3) {
                $slot = count($supplierSlots) + 1;
                $supplierSlots[$key] = $slot;
                $values['supplier_'.$slot] = $name;
                $values['rate_'.$slot] = $quoteData['rate'];
            }
            if (isset($supplierSlots[$key]) && !empty($quote['filename'])) {
                $fileKey = 'quotation_'.$supplierSlots[$key];
                $values[$fileKey] = $values[$fileKey] ?? $quote;
            }
        }
        $values['quote_at'] = $timestamp($latestQuote);
        foreach ($quotes as $quote) {
            $comparisonData = $quote['data'];
            if (!empty($comparisonData['comparison_name']) && ($comparisonData['quote_timestamp'] ?? '') === $latestQuote) {
                $values['compared_by'] = $comparisonData['comparison_name'];
                break;
            }
        }
        if ($selected) {
            $values['compared_by'] = $values['compared_by'] ?? ($selected['data']['compared_by'] ?? '');
            $values['final_supplier'] = self::supplierDisplay($selected['data'],$rows);
            $values['final_rate'] = $selected['data']['rate'];
        }
        return $values;
    }

    private static function indentDisplayDate($date) {
        return $date ? implode('/',array_reverse(explode('-',$date))) : '';
    }

    private static function indentRegister($rows,$pendingOnly=false) {
        $pos = [];
        $received = [];
        foreach ($rows as $row) {
            if ($row['kind'] === 'po' && $row['status'] === 'issued') {
                $pos[$row['id']] = (float)$row['data']['qty'];
            }
        }
        foreach ($rows as $row) {
            $id = $row['data']['po'] ?? 0;
            if (!isset($pos[$id])) {
                continue;
            }
            if ($row['kind'] === 'cancel') {
                $pos[$id] -= $row['data']['qty'];
            } elseif ($row['kind'] === 'grn' && $row['status'] === 'approved') {
                $received[$id] = ($received[$id] ?? 0) + $row['data']['qty'];
            }
        }
        $byIndent = [];
        foreach ($pos as $id=>$qty) {
            $line = $rows[$id]['data']['indent'];
            $byIndent[$line]['ordered'] = ($byIndent[$line]['ordered'] ?? 0) + max(0,$qty);
            $byIndent[$line]['received'] = ($byIndent[$line]['received'] ?? 0) + ($received[$id] ?? 0);
        }
        $groups = [];
        $groupItems = [];
        foreach ($rows as $row) {
            if ($row['kind'] !== 'indent') {
                continue;
            }
            $data = $row['data'];
            $id = $row['id'];
            $group = self::indentGroup($row);
            $boq = $rows[$data['boq']]['data'] ?? [];
            $status = $row['status'];
            if ($pendingOnly) {
                $groupItems[$group][] = [
                    $data['line_number'] ?? 1,$boq['name'] ?? '',$data['unit'] ?? ($boq['unit'] ?? ''),
                    $data['qty'],$data['excess'] ?? '',ucwords($status),
                ];
            }
            if (!isset($groups[$group])) {
                $groups[$group] = [
                    'id'=>$id,'number'=>$data['indent_number'] ?? '#'.$id,'group'=>$group,
                    'date'=>$data['indent_date'] ?? '',
                    'site'=>self::siteNumberWithName($rows[$data['site'] ?? ($boq['site'] ?? 0)]['data'] ?? []),
                    'requested'=>$data['requested_name'] ?? '', 'timestamp'=>$data['indent_timestamp'] ?? '',
                    'total'=>0,'received'=>0,'pending_receive'=>0,'ordered'=>0,'pending_order'=>0,
                    'target'=>'','decision'=>'','by'=>'','statuses'=>[],'remarks'=>[],'pending_lines'=>0,
                ];
            }
            $entry = &$groups[$group];
            $ordered = $byIndent[$id]['ordered'] ?? 0;
            $receivedQty = $byIndent[$id]['received'] ?? 0;
            $entry['statuses'][$status] = true;
            if ($status === 'pending') {
                $entry['pending_lines']++;
            }
            $entry['total'] += $data['qty'];
            $entry['ordered'] += $ordered;
            $entry['received'] += $receivedQty;
            $entry['pending_receive'] += max(0,$ordered-$receivedQty);
            if ($status === 'approved') {
                $entry['pending_order'] += max(0,$data['qty']-$ordered);
            }
            if (!empty($data['due']) && (!$entry['target'] || $data['due'] < $entry['target'])) {
                $entry['target'] = $data['due'];
            }
            if (!empty($data['decision_at']) && $data['decision_at'] > $entry['decision']) {
                $entry['decision'] = $data['decision_at'];
                $entry['by'] = $data['decision_by'] ?? '';
            }
            if (!empty($data['reason'])) {
                $entry['remarks'][$data['reason']] = true;
            }
            unset($entry);
        }
        $columns = [
            'date'=>'Indent Date','number'=>'Indent No.','site'=>'Site Number with Name','requested'=>'Requested By',
            'pdf'=>'PDF','timestamp'=>'Timestamp','total'=>'Total Quantity','received'=>'Quantity Received',
            'pending_receive'=>'Pending to Receive','target'=>'Indent Target Date','decision'=>'Approved Timestamp',
            'by'=>'Approved/Rejected By','status'=>'Status','remarks'=>'Remarks',
            'pending_order'=>'Pending To Order','ordered'=>'Quantity Ordered',
        ];
        if ($pendingOnly) {
            $groups = array_filter($groups,static function ($entry) { return $entry['pending_lines'] > 0; });
            $columns = [
                'number'=>'Indent No.','date'=>'Indent Date','site'=>'Site Number with Name',
                'requested'=>'Requested By','target'=>'Target Date','pending_lines'=>'Pending Items',
                'total'=>'Total Quantity','pdf'=>'PDF','actions'=>'Actions',
            ];
        }
        [$pageRows,$page,$pages,$total] = self::pagedRows($groups,array_diff_key($columns,['actions'=>true]),static function ($row) {
            $row['pdf'] = self::exportLink('PDF','frontier_indent_pdf',$row['id']);
            $row['status'] = implode(' / ',array_keys($row['statuses']));
            $row['remarks'] = implode(' · ',array_keys($row['remarks']));
            foreach (['date','target'] as $field) {
                $row[$field] = self::indentDisplayDate($row[$field] ?? '');
            }
            foreach (['timestamp','decision'] as $field) {
                $row[$field] = empty($row[$field]) ? '' : mysql2date('d/m/Y H:i:s',$row[$field],false);
            }
            return $row;
        });
        echo '<div class="frontier-table frontier-master-table" tabindex="0" role="region" aria-label="Indent register"><table class="widefat striped"><thead><tr>';
        foreach ($columns as $label) {
            echo '<th scope="col">'.esc_html($label).'</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($pageRows as $entry) {
            $id = (int)$entry['id'];
            echo $pendingOnly ? '<tr class="frontier-indent-approval-row" data-indent-row="'.esc_attr($id).'">' : '<tr>';
            foreach ($columns as $field=>$label) {
                echo '<td>';
                if ($field === 'number' && $pendingOnly) {
                    echo '<button type="button" class="frontier-indent-number" data-indent-toggle="items" aria-expanded="false" aria-controls="frontier-indent-items-'.esc_attr($id).'">'.esc_html((string)$entry['number']).'</button>';
                } elseif ($field === 'number') {
                    $url = add_query_arg('indent_group',$entry['group'],self::url('indent_items'));
                    echo '<a href="'.esc_url($url).'">'.esc_html((string)$entry['number']).'</a>';
                } elseif ($field === 'actions') {
                    echo '<button type="button" class="button frontier-add" data-indent-toggle="update" aria-haspopup="dialog" aria-expanded="false" aria-controls="frontier-indent-update-'.esc_attr($id).'">Update</button>';
                    self::indentUpdateDialog($entry);
                } elseif ($field === 'pdf') {
                    $id = $entry['id'];
                    $url = add_query_arg(['action'=>'frontier_indent_pdf','id'=>$id],admin_url('admin-post.php'));
                    echo '<a class="button frontier-download" href="'.esc_url(wp_nonce_url($url,'frontier_indent_pdf_'.$id)).'">PDF</a>';
                } else {
                    $value = $entry[$field] ?? '';
                    if ($field === 'status') {
                        $value = implode(' / ',array_map('ucwords',array_keys($entry['statuses'])));
                    } elseif ($field === 'remarks') {
                        $value = implode(' · ',array_keys($entry['remarks']));
                    } elseif (in_array($field,['date','target'],true)) {
                        $value = self::indentDisplayDate($value);
                    } elseif (in_array($field,['timestamp','decision'],true) && $value !== '') {
                        $value = mysql2date('d/m/Y H:i:s',$value,false);
                    } elseif (is_float($value)) {
                        $value = round($value,3);
                    }
                    echo esc_html((string)$value);
                }
                echo '</td>';
            }
            echo '</tr>';
            if ($pendingOnly) {
                self::indentApprovalPanels($id,$groupItems[$entry['group']],count($columns));
            }
        }
        if (!$total) {
            echo '<tr><td colspan="'.count($columns).'">'.($pendingOnly ? 'No indents pending approval.' : 'No indents yet.').'</td></tr>';
        }
        echo '</tbody></table></div>';
        self::pagination($page,$pages,$total);
    }

    private static function indentApprovalPanels($id,$items,$colspan) {
        echo '<tr class="frontier-indent-panel" id="frontier-indent-items-'.esc_attr($id).'" hidden><td colspan="'.(int)$colspan.'">';
        echo '<table class="frontier-indent-item-table" aria-label="Indent items"><thead><tr>';
        foreach (['Sl No.','Item','Unit','Quantity Requested','Excess Quantity','Status'] as $label) {
            echo '<th scope="col">'.esc_html($label).'</th>';
        }
        echo '</tr></thead><tbody>';
        foreach (array_reverse($items) as $item) {
            echo '<tr>';
            foreach ($item as $value) {
                echo '<td>'.esc_html((string)$value).'</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></td></tr>';
    }

    private static function indentUpdateDialog($entry) {
        $id = (int)$entry['id'];
        echo '<dialog class="frontier-indent-dialog" id="frontier-indent-update-'.esc_attr($id).'" aria-labelledby="frontier-indent-title-'.esc_attr($id).'">';
        echo '<div class="frontier-indent-dialog-header"><h2 id="frontier-indent-title-'.esc_attr($id).'">Update Indent</h2>';
        echo '<button type="button" class="button frontier-dialog-close" data-indent-close aria-label="Close">×</button></div>';
        echo '<p class="frontier-indent-dialog-context">'.esc_html('Indent No. '.$entry['number'].' · '.$entry['site']).'</p>';
        self::formStart('indent','update_indent_group',$id);
        echo '<fieldset><legend>'.self::requiredLabel('Status').'</legend><div class="frontier-indent-status-options">';
        foreach (['approved'=>'Approved','rejected'=>'Rejected'] as $status=>$label) {
            echo '<label class="frontier-indent-status frontier-indent-status-'.esc_attr($status).'">';
            echo '<input type="radio" name="approval_status" value="'.esc_attr($status).'" required><span>'.esc_html($label).'</span></label>';
        }
        echo '</div></fieldset>';
        echo '<p><label>Update By <input readonly value="'.esc_attr(wp_get_current_user()->display_name).'"></label></p>';
        echo '<p><label>Remarks <textarea name="approval_remarks" rows="3" maxlength="2000"></textarea></label></p>';
        echo '<div class="frontier-indent-dialog-actions"><button type="button" class="button" data-indent-close>Cancel</button>';
        self::submitButton('Save Update',true);
        echo '</div></form></dialog>';
    }

    public static function downloadIndentPdf() {
        $id = absint($_GET['id'] ?? 0);
        if (!self::canView('indent_master')) {
            wp_die('Access denied.','Frontier',['response'=>403]);
        }
        check_admin_referer('frontier_indent_pdf_'.$id);
        $rows = self::rows();
        $indent = $rows[$id] ?? null;
        if (!$indent || $indent['kind'] !== 'indent') {
            wp_die('Indent not found.','Frontier',['response'=>404]);
        }
        $group = self::indentGroup($indent);
        $data = $indent['data'];
        $boq = $rows[$data['boq']]['data'] ?? [];
        $site = self::siteNumberWithName($rows[$data['site'] ?? ($boq['site'] ?? 0)]['data'] ?? []);
        $lines = [];
        foreach (array_reverse($rows) as $row) {
            if ($row['kind'] !== 'indent' || self::indentGroup($row) !== $group) {
                continue;
            }
            $line = $row['data'];
            $item = $rows[$line['boq']]['data'] ?? [];
            $lines[] = [$line['line_number'] ?? 1,$item['name'] ?? '',$line['qty'],$line['unit'] ?? ($item['unit'] ?? '')];
        }
        $number = $data['indent_number'] ?? $id;
        $pdf = Frontier_Boq_Pdf::build($number,$site,self::indentDisplayDate($data['indent_date'] ?? ''),
            $data['requested_name'] ?? '',$lines,'Indent','Requested By');
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="indent-'.absint($number).'.pdf"');
        header('Content-Length: '.strlen($pdf));
        echo $pdf;
        exit;
    }

    private static function indentForm($rows) {
        $used = [];
        foreach ($rows as $row) {
            if ($row['kind'] === 'indent' && in_array($row['status'],['pending','approved'],true)) {
                $id = $row['data']['boq'];
                $used[$id] = ($used[$id] ?? 0) + $row['data']['qty'];
            }
        }
        $sites = [];
        $catalog = [];
        foreach ($rows as $row) {
            $data = $row['data'];
            if ($row['kind'] !== 'boq' || empty($data['site']) || ($rows[$data['site']]['kind'] ?? '') !== 'site') {
                continue;
            }
            $site = $rows[$data['site']];
            $sites[$site['id']] = $site;
            $catalog[$site['id']][] = [
                'id'=>(int)$row['id'],'name'=>$data['name'],'number'=>$data['boq_number'] ?? '#'.$row['id'],
                'line'=>$data['line_number'] ?? 1,'unit'=>$data['unit'],'qty'=>(float)$data['qty'],
                'used'=>round($used[$row['id']] ?? 0,3),
            ];
        }
        self::formStart('indent','create_indent');
        echo '<input type="hidden" name="indent_lines"><input type="hidden" name="indent_token" value="'.esc_attr(wp_generate_uuid4()).'">';
        echo '<div class="frontier-fields">';
        self::field($rows,'indent','due','Indent Date',current_time('Y-m-d'));
        self::field($rows,'indent','employee','Requested By','');
        self::field($sites,'indent','site','Site Number with Name','');
        echo '<p><label>Indent Target Date <input type="date" name="target_date"></label></p>';
        echo '</div><div class="frontier-master-toolbar"><h2>Indent Items</h2><button type="button" class="button frontier-indent-add" disabled>＋ Add More</button></div>';
        echo '<p class="frontier-indent-message" role="status"></p>';
        echo '<div class="frontier-table"><table class="widefat striped"><thead><tr>';
        foreach (['Sl No.','Item Name','Unit','Quantity Required','Already Indented','Pending to Indent','Indent Now','Action'] as $label) {
            echo '<th scope="col">'.esc_html($label).'</th>';
        }
        echo '</tr></thead><tbody></tbody></table></div>';
        echo '<script type="application/json" class="frontier-indent-data">'.wp_json_encode($catalog,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).'</script>';
        echo '<p><a class="button" href="'.esc_url(self::url('indent_master')).'">Cancel</a> ';
        echo '<button class="button button-primary frontier-indent-submit" type="submit" disabled>Submit Indent</button></p>';
        echo '<noscript><p>Enable JavaScript to add indent items.</p></noscript></form>';
    }

    private static function saveIndent($rows) {
        $site = $rows[absint(self::input('site'))] ?? null;
        $employee = $rows[absint(self::input('employee'))] ?? null;
        $dateText = self::input('due');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d',$dateText);
        self::requireRule($site && $site['kind'] === 'site','Select a site.');
        self::requireRule($employee && $employee['kind'] === 'employee','Select Requested By.');
        self::requireRule($date && $date->format('Y-m-d') === $dateText,'Enter a valid indent date.');
        $raw = $_POST['indent_lines'] ?? '';
        $lines = is_string($raw) && strlen($raw) <= 100000 ? json_decode(wp_unslash($raw),true) : null;
        self::requireRule(is_array($lines) && count($lines) > 0 && count($lines) <= 100,'Add between 1 and 100 indent items.');
        $token = self::input('indent_token');
        self::requireRule(wp_is_uuid($token,4),'Reopen the Indent Form and try again.');
        $last = 0;
        foreach ($rows as $row) {
            if ($row['kind'] === 'indent') {
                if (($row['data']['indent_token'] ?? '') === $token) {
                    return;
                }
                $last = max($last,(int)($row['data']['indent_number'] ?? 0));
            }
        }
        $targetDate = self::input('target_date');
        if ($targetDate !== '') {
            $target = DateTimeImmutable::createFromFormat('!Y-m-d',$targetDate);
            self::requireRule($target && $target->format('Y-m-d') === $targetDate,'Enter a valid indent target date.');
        }
        $header = ['indent_number'=>self::nextNumber('indent',$last),'indent_date'=>$dateText,'indent_token'=>$token,
            'indent_timestamp'=>current_time('mysql'),'due'=>$targetDate,
            'requested_by'=>(int)$employee['id'],'requested_name'=>$employee['data']['name'],'site'=>(int)$site['id']];
        $selected = [];
        global $wpdb;
        foreach (array_values($lines) as $index=>$line) {
            $number = $index+1;
            $id = is_array($line) && is_int($line['boq'] ?? null) ? $line['boq'] : 0;
            $qty = is_array($line) && is_string($line['qty'] ?? null) ? $line['qty'] : '';
            self::requireRule(($rows[$id]['kind'] ?? '') === 'boq' && !isset($selected[$id]),
                'Choose a distinct BOQ item on row '.$number.'.');
            self::requireRule(preg_match('/^\d{1,10}(?:\.\d{1,3})?$/D',$qty) && (float)$qty > 0,
                'Enter a valid quantity on row '.$number.'.');
            $selected[$id] = true;
            $data = $header + ['boq'=>$id,'qty'=>(float)$qty,'line_number'=>$number];
            self::validateIndent($rows,$data,0);
            if ($wpdb->insert(self::table(),['kind'=>'indent','status'=>'pending','details'=>wp_json_encode($data)]) !== 1) {
                throw new RuntimeException('Indent could not be saved.');
            }
        }
    }

    private static function pendingInvoiceQuantity($rows,$po) {
        return max(0,round($po['data']['qty'] - self::sum($rows,'cancel','po',$po['id'])
            - self::sum($rows,'match','po',$po['id']),3));
    }

    private static function purchaseInvoiceView($rows,$master) {
        $editable = !$master && self::can('match');
        $listing = array_filter($rows,static function ($row) use ($master) {
            return $master ? $row['kind'] === 'match' : $row['kind'] === 'po';
        });
        $columns = ['PO No. with Supplier Name','PO Date',$master ? 'Indent Number with Site Name' : 'Indent Number',
            'PO No.','Indent Date','Site Name','Item Name','Order Quantity','Supplier Finalized','Rate Finalized','Pending for Invoice'];
        $columns = array_merge($columns,$master ? ['Quantity Invoiced','Rate as per Invoice','Invoice Identifier',
            'Invoice No.','Invoice Date','Invoice Copy','Invoice Amount'] : ['Invoice Qty','Invoice Rate','Invoice Identifier']);
        $invoiceValues = static function ($row) use ($rows,$master) {
            $po = $master ? ($rows[$row['data']['po'] ?? 0] ?? []) : $row;
            $order = $po ? self::purchaseOrderValues($rows,$po) : [];
            $pending = $po && $po['status'] !== 'cancelled' ? self::pendingInvoiceQuantity($rows,$po) : 0;
            $indent = $order['indent_number'] ?? '';
            if ($master) {
                $indent = implode(' · ',array_filter([(string)$indent,$order['site_name'] ?? ''],'strlen'));
            }
            $values = [$order['po_supplier'] ?? '',$order['po_date'] ?? '',$indent,$order['ref'] ?? '',
                $order['indent_date'] ?? '',$order['site_name'] ?? '',$order['item_name'] ?? '',$order['qty'] ?? '',
                $order['supplier'] ?? '',$order['rate'] ?? '',$po ? $pending : ''];
            return [$values,$po,$pending];
        };
        $searchColumns = $master ? $columns : array_slice($columns,0,11);
        [$pageRows,$page,$pages,$total] = self::pagedRows($listing,$searchColumns,static function ($row) use ($invoiceValues,$rows,$master) {
            [$values] = $invoiceValues($row);
            if (!$master) {
                return $values;
            }
            $data = $row['data'];
            $invoice = $rows[$data['invoice'] ?? 0] ?? [];
            $invoiceData = $invoice['data'] ?? [];
            return array_merge($values,[$data['qty'] ?? '',$data['rate'] ?? '',
                $data['invoice_identifier'] ?? ($invoice ? self::invoiceIdentifier($invoice,$rows) : ''),
                $invoiceData['ref'] ?? '',self::indentDisplayDate($invoiceData['due'] ?? ''),
                !empty($invoice['filename']) && self::canFile('invoice') ? self::exportLink($invoice['filename'],'frontier_file',$invoice['id']) : '',
                number_format((float)$data['qty'] * (float)$data['rate'],2)]);
        });
        if ($editable) {
            self::formStart('match','link_po_invoice');
            if ($total) {
                echo '<p>';
                self::submitButton('Save Invoice Links',true);
                echo '</p>';
            }
        }
        echo '<div class="frontier-table frontier-master-table" tabindex="0" role="region" aria-label="PO item invoices"><table class="widefat striped"><thead><tr>';
        foreach ($columns as $column) {
            echo '<th scope="col">'.esc_html($column).'</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($pageRows as $row) {
            [$values,$po,$pending] = $invoiceValues($row);
            echo '<tr>';
            foreach ($values as $value) {
                echo '<td>'.esc_html((string)$value).'</td>';
            }
            if ($master) {
                $data = $row['data'];
                $invoice = $rows[$data['invoice'] ?? 0] ?? [];
                $invoiceData = $invoice['data'] ?? [];
                foreach ([$data['qty'] ?? '',$data['rate'] ?? '',$data['invoice_identifier'] ?? ($invoice ? self::invoiceIdentifier($invoice,$rows) : ''),
                    $invoiceData['ref'] ?? '',self::indentDisplayDate($invoiceData['due'] ?? '')] as $value) {
                    echo '<td>'.esc_html((string)$value).'</td>';
                }
                echo '<td>';
                if (!empty($invoice['filename']) && self::canFile('invoice')) {
                    $url = add_query_arg(['action'=>'frontier_file','id'=>$invoice['id']],admin_url('admin-post.php'));
                    echo '<a class="button frontier-download" href="'.esc_url(wp_nonce_url($url,'frontier_file_'.$invoice['id'])).'">Download</a>';
                }
                echo '</td><td>'.esc_html(number_format((float)$data['qty'] * (float)$data['rate'],2)).'</td>';
            } else {
                if ($editable && $pending > 0) {
                    self::purchaseInvoiceInputs($rows,$po,$pending);
                } else {
                    echo '<td></td><td></td><td></td>';
                }
            }
            echo '</tr>';
        }
        if (!$total) {
            echo '<tr><td colspan="'.count($columns).'">No records yet.</td></tr>';
        }
        echo '</tbody></table></div>';
        if ($editable) {
            echo '</form>';
            echo <<<'HTML'
<script>
(() => {
    const form = document.currentScript.previousElementSibling;
    form.addEventListener('change', event => {
        if (!event.target.matches('[data-invoice-identifier]')) return;
        const row = event.target.closest('tr');
        const qty = row.querySelector('[data-invoice-qty]');
        const rate = row.querySelector('[data-invoice-rate]');
        if (event.target.value) {
            if (qty.value === '') qty.value = qty.max;
            if (rate.value === '') rate.value = rate.dataset.defaultRate;
        } else {
            if (qty.value === qty.max) qty.value = '';
            if (rate.value === rate.dataset.defaultRate) rate.value = '';
        }
    });
    const hasChanges = () => Array.from(form.elements).some(field =>
        field.name.startsWith('invoice_links[') && field.value !== '');
    const warn = event => {
        if (hasChanges()) {
            event.preventDefault();
            event.returnValue = '';
        }
    };
    window.addEventListener('beforeunload', warn);
    form.addEventListener('submit', () => window.removeEventListener('beforeunload', warn));
})();
</script>
HTML;
        }
        self::pagination($page,$pages,$total);
    }

    private static function invoiceIdentifier($invoice,$rows) {
        $data = $invoice['data'];
        $supplier = $rows[$data['supplier'] ?? 0]['data']['name'] ?? ($data['name'] ?? '');
        return implode(' - ',array_filter([$data['ref'] ?? '',self::indentDisplayDate($data['due'] ?? ''),$supplier],'strlen'));
    }

    private static function purchaseInvoiceInputs($rows,$po,$pending) {
        $invoices = array_filter($rows,static function ($invoice) use ($rows,$po) {
            return $invoice['kind'] === 'invoice' && self::sameSupplier($po['data'],$invoice['data'])
                && (!isset($invoice['data']['qty']) || $invoice['data']['qty'] > self::sum($rows,'match','invoice',$invoice['id']));
        });
        if (!$invoices) {
            echo '<td></td><td></td><td>No available invoice</td>';
            return;
        }
        $prefix = 'invoice_links['.esc_attr($po['id']).']';
        echo '<td><input type="number" min="0.001" max="'.esc_attr($pending).'" step="0.001" style="min-width:96px" aria-label="Invoice Qty" data-invoice-qty name="'.$prefix.'[qty]"></td>';
        echo '<td><input type="number" min="0" step="0.001" style="min-width:96px" aria-label="Invoice Rate" data-invoice-rate data-default-rate="'.esc_attr($po['data']['rate'] ?? '').'" name="'.$prefix.'[rate]"></td>';
        echo '<td style="min-width:260px"><select name="'.$prefix.'[invoice]" aria-label="Invoice Identifier" data-invoice-identifier data-dropdown-overlay><option value="">Select invoice</option>';
        foreach ($invoices as $invoice) {
            echo '<option value="'.esc_attr($invoice['id']).'">'.esc_html(self::invoiceIdentifier($invoice,$rows)).'</option>';
        }
        echo '</select></td>';
    }

    private static function savePurchaseInvoiceLinks($rows) {
        global $wpdb;
        $links = $_POST['invoice_links'] ?? [];
        self::requireRule(is_array($links) && count($links) <= 25,'Check the invoice links.');
        $saved = false;
        foreach ($links as $id=>$line) {
            self::requireRule(is_array($line) && is_string($line['qty'] ?? null)
                && is_string($line['rate'] ?? null) && is_string($line['invoice'] ?? null),'Check the invoice fields.');
            if ($line['qty'] === '' && $line['rate'] === '' && $line['invoice'] === '') {
                continue;
            }
            $data = self::purchaseInvoiceLinkData($rows,$id,$line);
            if ($wpdb->insert(self::table(),['kind'=>'match','status'=>'recorded','details'=>wp_json_encode($data)]) !== 1) {
                throw new RuntimeException('Invoice link could not be saved.');
            }
            $newId = (int)$wpdb->insert_id;
            $rows[$newId] = ['id'=>$newId,'kind'=>'match','status'=>'recorded','data'=>$data];
            $saved = true;
        }
        self::requireRule($saved,'Enter invoice details on at least one row.');
    }

    private static function purchaseInvoiceLinkData($rows,$id,$line) {
        $po = $rows[absint($id)] ?? [];
        $invoice = $rows[absint($line['invoice'])] ?? [];
        self::requireRule(($po['kind'] ?? '') === 'po' && $po['status'] !== 'cancelled','Select a valid PO item.');
        self::requireRule(($invoice['kind'] ?? '') === 'invoice' && self::sameSupplier($po['data'],$invoice['data']),
            'The invoice and PO suppliers must match.');
        $qty = trim($line['qty']);
        $rate = trim($line['rate']);
        self::requireRule(preg_match('/^\d{1,10}(?:\.\d{1,3})?$/D',$qty) && (float)$qty > 0
            && (float)$qty <= self::pendingInvoiceQuantity($rows,$po),'Invoice Qty exceeds the PO item balance or is invalid.');
        self::requireRule(!isset($invoice['data']['qty']) || (float)$qty <= round($invoice['data']['qty'] - self::sum($rows,'match','invoice',$invoice['id']),3),
            'Invoice Qty exceeds the unmatched invoice quantity.');
        self::requireRule(preg_match('/^\d{1,10}(?:\.\d{1,3})?$/D',$rate),'Enter a valid Invoice Rate.');
        return ['po'=>(int)$po['id'],'invoice'=>(int)$invoice['id'],'qty'=>(float)$qty,'rate'=>(float)$rate,
            'invoice_identifier'=>self::invoiceIdentifier($invoice,$rows)];
    }

    private static function goodsReceiptNumberId($row) {
        return (int)($row['data']['grn_group_id'] ?? $row['id']);
    }

    private static function goodsReceiptGroup($rows,$receipt) {
        $id = self::goodsReceiptNumberId($receipt);
        $lines = array_filter($rows,static function ($row) use ($id) {
            return $row['kind'] === 'grn' && self::goodsReceiptNumberId($row) === $id;
        });
        ksort($lines,SORT_NUMERIC);
        return $lines;
    }

    private static function goodsReceiptValues($rows,$row) {
        $data = $row['data'];
        $po = $rows[$data['po'] ?? 0] ?? null;
        $order = $po ? self::purchaseOrderValues($rows,$po) : [];
        $already = $data['already_received'] ?? 0;
        $pending = $data['pending_before'] ?? '';
        if ($po && $pending === '') {
            $earlier = array_filter($rows,static function ($record) use ($row) {
                return (int)$record['id'] < (int)$row['id'];
            });
            $already = self::sum($earlier,'grn','po',$po['id']);
            $pending = max(0,self::balance($earlier,$po));
        }
        return [
            'grn_no'=>'GRN - '.str_pad((string)($data['grn_number'] ?? self::goodsReceiptNumberId($row)),4,'0',STR_PAD_LEFT),
            'grn_date'=>self::indentDisplayDate($data['due'] ?? ''),
            'timestamp'=>empty($data['grn_timestamp']) ? '' : mysql2date('d/m/Y H:i:s',$data['grn_timestamp'],false),
            'slip_no'=>$data['ref'] ?? '', 'supplier'=>$order['supplier'] ?? '',
            'po_no'=>$order['ref'] ?? '', 'po_date'=>$order['po_date'] ?? '',
            'prepared'=>$data['prepared_name'] ?? '', 'site_name'=>$order['site_name'] ?? '',
            'sl'=>$order['line_number'] ?? '', 'item_name'=>$order['item_name'] ?? '',
            'unit'=>$order['unit'] ?? '', 'ordered'=>$order['qty'] ?? '',
            'already'=>$po ? $already : '', 'pending'=>$pending,'received'=>$data['qty'] ?? '',
        ];
    }

    public static function downloadGoodsReceiptPdf() {
        $id = absint($_GET['id'] ?? 0);
        if (!self::canView('grn')) {
            wp_die('Access denied.','Frontier',['response'=>403]);
        }
        check_admin_referer('frontier_grn_pdf_'.$id);
        $rows = self::rows();
        $receipt = $rows[$id] ?? [];
        if (($receipt['kind'] ?? '') !== 'grn') {
            wp_die('Goods receipt not found.','Frontier',['response'=>404]);
        }
        $data = self::goodsReceiptValues($rows,$receipt);
        $items = [];
        foreach (self::goodsReceiptGroup($rows,$receipt) as $line) {
            $item = self::goodsReceiptValues($rows,$line);
            $items[] = [$item['sl'],$item['item_name'],$item['received'],$item['unit']];
        }
        $pdf = Frontier_Boq_Pdf::build($data['grn_no'],$data['site_name'],$data['grn_date'],$data['prepared'],
            $items,'Goods Receipt Note','Prepared By',['Supplier: '.$data['supplier'],'PO No.: '.$data['po_no'],
                'Slip No.: '.$data['slip_no']]);
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="goods-receipt-'.self::goodsReceiptNumberId($receipt).'.pdf"');
        header('Content-Length: '.strlen($pdf));
        echo $pdf;
        exit;
    }

    private static function goodsReceiptForm($rows) {
        $orders = [];
        foreach ($rows as $row) {
            if ($row['kind'] === 'po' && self::canReceivePurchaseOrder($rows,$row)) {
                $orders[(int)($row['data']['po_group_id'] ?? $row['id'])][] = $row;
            }
        }
        if (!$orders) {
            echo '<p>No purchase orders have quantities pending receipt.</p>';
            return;
        }
        $source = $rows[absint($_GET['source'] ?? 0)] ?? [];
        $prefill = ($source['kind'] ?? '') === 'po' ? (int)($source['data']['po_group_id'] ?? $source['id']) : 0;
        self::formStart('grn','create_goods_receipt');
        echo '<div class="frontier-fields">';
        self::field($rows,'grn','due','GRN Date',current_time('Y-m-d'));
        echo '<p><label>'.self::requiredLabel('PO No. with Supplier').'<select name="receipt_order" required><option value="">Select purchase order</option>';
        foreach ($orders as $id=>$lines) {
            $data = self::purchaseOrderValues($rows,$lines[0]);
            echo '<option value="'.esc_attr($id).'" '.selected($prefill,$id,false);
            foreach (['supplier','po_date','indent_number','site_name'] as $field) {
                echo ' data-'.esc_attr($field).'="'.esc_attr($data[$field]).'"';
            }
            echo '>'.esc_html($data['po_supplier']).'</option>';
        }
        echo '</select></label></p>';
        self::field($rows,'grn','employee','GRN Prepared By','');
        self::field($rows,'grn','ref','GRN Slip No.','');
        echo '</div><div class="frontier-fields">';
        foreach (['supplier'=>'Supplier','po_date'=>'PO Date','indent_number'=>'Indent No.','site_name'=>'Site Number with Name'] as $field=>$label) {
            echo '<p><label>'.esc_html($label).'<input type="text" data-grn-detail="'.esc_attr($field).'" readonly></label></p>';
        }
        echo '</div><h2>PO Items</h2><div class="frontier-table frontier-master-table" tabindex="0" role="region" aria-label="Items to receive"><table class="widefat striped"><thead><tr>';
        foreach (['Sl No.','PO Item No.','Item Name','Unit','Pending to Receive','Quantity Received'] as $label) {
            echo '<th scope="col">'.esc_html($label).'</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($orders as $id=>$lines) {
            usort($lines,static function ($left,$right) {
                return (int)$left['id'] <=> (int)$right['id'];
            });
            foreach ($lines as $index=>$line) {
                $data = self::purchaseOrderValues($rows,$line);
                $pending = self::balance($rows,$line);
                echo '<tr data-grn-order="'.esc_attr($id).'" hidden>';
                foreach ([$index+1,$data['ref'].'/'.$data['line_number'],$data['item_name'],$data['unit'],$pending] as $value) {
                    echo '<td>'.esc_html((string)$value).'</td>';
                }
                echo '<td><input type="number" min="0" step="0.001" max="'.esc_attr($pending).'" name="received['.esc_attr($line['id']).']" aria-label="'.esc_attr('Quantity received for '.$data['item_name']).'" disabled>';
                echo '<small data-grn-warning hidden>Exceeds Pending to Receive</small></td></tr>';
            }
        }
        echo '</tbody></table></div><p><a class="button" href="'.esc_url(self::url('pending_grn')).'">Cancel</a> ';
        echo '<button type="submit" class="button button-primary" data-grn-save disabled>Create GRN</button></p>';
        echo <<<'HTML'
<script>
(() => {
    const form = document.currentScript.closest('form');
    const order = form.elements.receipt_order;
    const rows = form.querySelectorAll('[data-grn-order]');
    const save = form.querySelector('[data-grn-save]');
    const refresh = () => {
        const option = order.selectedOptions[0];
        for (const field of form.querySelectorAll('[data-grn-detail]')) {
            field.value = option?.getAttribute('data-' + field.dataset.grnDetail) ?? '';
        }
        let hasQuantity = false;
        let invalid = false;
        for (const row of rows) {
            const input = row.querySelector('input');
            const visible = row.dataset.grnOrder === order.value;
            row.hidden = !visible;
            input.disabled = !visible;
            if (!visible) continue;
            row.querySelector('[data-grn-warning]').hidden = !input.validity.rangeOverflow;
            invalid ||= !input.validity.valid || (input.value !== '' && !/^\d{1,10}(?:\.\d{1,3})?$/.test(input.value));
            hasQuantity ||= input.valueAsNumber > 0;
        }
        save.disabled = invalid || !hasQuantity || !form.elements.due.value
            || !form.elements.employee.value || !form.elements.ref.value.trim();
    };
    form.addEventListener('change', refresh);
    form.addEventListener('input', refresh);
    refresh();
})();
</script>
HTML;
        echo '</form>';
    }

    private static function saveGoodsReceipt($rows) {
        global $wpdb;
        $orderId = absint(self::input('receipt_order'));
        $order = $rows[$orderId] ?? [];
        self::requireRule(($order['kind'] ?? '') === 'po'
            && (int)($order['data']['po_group_id'] ?? $order['id']) === $orderId,'Select a purchase order.');
        $employee = $rows[absint(self::input('employee'))] ?? [];
        self::requireRule(($employee['kind'] ?? '') === 'employee','Select GRN Prepared By.');
        $due = self::input('due');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d',$due);
        self::requireRule($date && $date->format('Y-m-d') === $due,'Enter a valid GRN Date.');
        $ref = self::input('ref');
        self::requireRule($ref !== '' && strlen($ref) <= 200,'Enter a valid GRN Slip No.');
        $submitted = $_POST['received'] ?? [];
        self::requireRule(is_array($submitted),'Check the quantities received.');
        $group = 0;
        $timestamp = current_time('mysql');
        $number = self::nextNumber('grn',self::lastNumbers($rows,0)['grn']);
        foreach ($submitted as $id=>$qty) {
            self::requireRule(is_string($qty),'Enter a valid Quantity Received.');
            if ($qty === '') {
                continue;
            }
            self::requireRule(preg_match('/^\d{1,10}(?:\.\d{1,3})?$/D',$qty),'Enter a valid Quantity Received.');
            if ((float)$qty === 0.0) {
                continue;
            }
            $line = $rows[$id] ?? [];
            self::requireRule(($line['kind'] ?? '') === 'po'
                && (int)($line['data']['po_group_id'] ?? $line['id']) === $orderId
                && self::canReceivePurchaseOrder($rows,$line),'This PO item is no longer available for receipt.');
            $data = ['po'=>(int)$id,'ref'=>$ref,'due'=>$due,'qty'=>(float)$qty,'reject'=>0,'grn_timestamp'=>$timestamp,'grn_number'=>$number,
                'already_received'=>self::sum($rows,'grn','po',$id),'pending_before'=>self::balance($rows,$line),
                'prepared_employee'=>(int)$employee['id'],'prepared_name'=>$employee['data']['name']];
            self::validateNew($rows,'grn',$data);
            if ($group) {
                $data['grn_group_id'] = $group;
            }
            if ($wpdb->insert(self::table(),['kind'=>'grn','status'=>'pending','details'=>wp_json_encode($data)]) !== 1) {
                throw new RuntimeException('Goods receipt could not be saved.');
            }
            if (!$group) {
                $group = (int)$wpdb->insert_id;
                $data['grn_group_id'] = $group;
                if ($wpdb->update(self::table(),['details'=>wp_json_encode($data)],['id'=>$group]) !== 1) {
                    throw new RuntimeException('Goods receipt could not be grouped.');
                }
            }
        }
        self::requireRule($group,'Enter Quantity Received for at least one item.');
        if ($order['status'] === 'draft') {
            self::updatePurchaseOrderGroup($rows,$orderId,'issue');
        }
    }

    private static function purchaseOrderItems($rows) {
        $quotes = [];
        foreach ($rows as $quote) {
            if ($quote['kind'] !== 'quote' || $quote['status'] !== 'selected') {
                continue;
            }
            $id = (int)($quote['data']['indent'] ?? 0);
            $indent = $rows[$id] ?? [];
            if (($indent['kind'] ?? '') !== 'indent' || $indent['status'] !== 'approved') {
                continue;
            }
            if (!isset($quotes[$id]) || ($quote['data']['compared_at'] ?? '') > ($quotes[$id]['data']['compared_at'] ?? '')) {
                $quotes[$id] = $quote;
            }
        }
        $items = [];
        foreach ($quotes as $indentId=>$quote) {
            $indent = $rows[$indentId];
            $pending = self::available($rows,$indent);
            if ($pending > 0) {
                $items[$quote['id']] = ['quote'=>$quote,'indent'=>$indent,'pending'=>$pending];
            }
        }
        return $items;
    }

    private static function purchaseOrderForm($rows) {
        $items = self::purchaseOrderItems($rows);
        if (!$items) {
            echo '<p>No items are pending purchase order.</p>';
            return;
        }
        $groups = [];
        foreach ($items as $item) {
            $indent = $item['indent'];
            $group = self::indentGroup($indent);
            if (isset($groups[$group])) {
                continue;
            }
            $details = self::supplierIndentDetails($indent,$rows);
            $data = $indent['data'];
            $boq = $rows[$data['boq'] ?? 0]['data'] ?? [];
            $site = $rows[$data['site'] ?? ($boq['site'] ?? 0)]['data'] ?? [];
            $contact = $rows[$site['employee'] ?? 0]['data'] ?? [];
            $groups[$group] = [
                'label'=>$details['indent_number'].' · '.$details['site_name'],
                'indent-date'=>$details['indent_date'],
                'address'=>$site['address'] ?? '',
                'contact'=>$contact['name'] ?? '',
                'mobile'=>$contact['mobile'] ?? '',
            ];
        }
        $source = $items[absint($_GET['source'] ?? 0)] ?? null;
        $prefill = $source ? self::indentGroup($source['indent']) : '';
        self::formStart('po','create_purchase_orders');
        echo '<div class="frontier-fields">';
        self::field($rows,'po','due','PO Date',current_time('Y-m-d'));
        echo '<p><label>'.self::requiredLabel('Indent No. with Site Name').'<select name="po_group" required><option value="">Select indent</option>';
        foreach ($groups as $group=>$details) {
            echo '<option value="'.esc_attr($group).'" '.selected($prefill,$group,false);
            foreach (['indent-date','address','contact','mobile'] as $field) {
                echo ' data-'.esc_attr($field).'="'.esc_attr($details[$field]).'"';
            }
            echo '>'.esc_html($details['label']).'</option>';
        }
        echo '</select></label></p>';
        self::field($rows,'po','employee','Prepared By','');
        echo '<p><label>'.self::requiredLabel('Target Date').'<input type="date" name="target_date" required></label></p></div>';
        echo '<div class="frontier-fields frontier-po-delivery">';
        foreach (['indent-date'=>'Indent Date','compared'=>'Compared By','address'=>'Delivery Address',
            'contact'=>'Site Contact Person','mobile'=>'Site Mobile No.'] as $field=>$label) {
            $editable = in_array($field,['address','contact','mobile'],true);
            $attributes = $editable ? ' name="delivery_'.esc_attr($field).'" maxlength="'.($field === 'address' ? 5000 : 200).'"' : ' readonly';
            echo '<p><label>'.esc_html($label).'<input type="'.($field === 'mobile' ? 'tel' : 'text').'" data-po-detail="'.esc_attr($field).'"'.$attributes.'></label></p>';
        }
        echo '</div><h2>PO Items</h2><div class="frontier-table frontier-master-table" tabindex="0" role="region" aria-label="Purchase order items"><table class="widefat striped" style="min-width:1120px"><thead><tr>';
        foreach (['Sl No.','Item Name','Unit','Supplier Finalized','Rate Finalized','Pending to Order','Order Qty','Amount','Brand','Remarks'] as $label) {
            echo '<th scope="col">'.esc_html($label).'</th>';
        }
        echo '</tr></thead><tbody>';
        uasort($items,static function ($left,$right) {
            return ($left['indent']['data']['line_number'] ?? 1) <=> ($right['indent']['data']['line_number'] ?? 1);
        });
        $suppliers = [];
        foreach ($items as $quoteId=>$item) {
            $quote = $item['quote']['data'];
            $supplierKey = self::purchaseOrderSupplierKey($item['quote']);
            $suppliers[$supplierKey] = [
                'label'=>self::supplierDisplay($quote,$rows),
                'terms'=>$rows[$quote['supplier'] ?? 0]['data']['term'] ?? '',
            ];
            $indent = $item['indent'];
            $details = self::supplierIndentDetails($indent,$rows);
            $compared = $quote['compared_by'] ?? $quote['comparison_name'] ?? '';
            echo '<tr data-po-group="'.esc_attr(self::indentGroup($indent)).'" data-po-supplier="'.esc_attr($supplierKey).'" data-rate="'.esc_attr($quote['rate']).'" data-compared="'.esc_attr($compared).'" hidden>';
            foreach ([$details['line_number'],$details['item_name'],$details['unit'],self::supplierDisplay($quote,$rows),
                number_format((float)$quote['rate'],((float)$quote['rate'] * 100 == round((float)$quote['rate'] * 100)) ? 2 : 3),
                $item['pending']] as $value) {
                echo '<td>'.esc_html((string)$value).'</td>';
            }
            $name = $details['item_name'];
            $prefix = 'po_lines['.esc_attr($quoteId).']';
            echo '<td><input type="number" min="0" step="0.001" max="'.esc_attr($item['pending']).'" aria-label="'.esc_attr('Order Qty for '.$name).'" name="'.$prefix.'[qty]" data-po-qty disabled style="min-width:96px"><small data-po-warning hidden>Exceeds Pending to Order</small></td>';
            echo '<td><output data-po-amount></output></td>';
            foreach (['brand'=>'Brand','remarks'=>'Remarks'] as $field=>$label) {
                echo '<td><textarea rows="1" maxlength="200" name="'.$prefix.'['.$field.']" aria-label="'.esc_attr($label.' for '.$name).'" data-po-autogrow disabled></textarea></td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div><p><strong>Total Amount <output data-po-total aria-live="polite"></output></strong></p>';
        echo '<p><a class="button" href="'.esc_url(self::url('pending_po')).'">Cancel</a> <button type="submit" class="button button-primary" data-po-save disabled>Create Purchase Order</button></p>';
        echo '<section data-po-terms hidden><h2>Supplier Terms</h2><div class="frontier-po-terms-grid">';
        foreach ($suppliers as $key=>$supplier) {
            echo '<p data-po-terms-supplier="'.esc_attr($key).'" hidden><label>'.esc_html($supplier['label']);
            echo '<textarea name="po_terms['.esc_attr($key).']" rows="3" maxlength="20000" disabled>'.esc_textarea($supplier['terms']).'</textarea></label></p>';
        }
        echo '</div></section>';
        echo <<<'HTML'
<script>
(() => {
    const form = document.currentScript.closest('form');
    const group = form.elements.po_group;
    const rows = Array.from(form.querySelectorAll('[data-po-group]'));
    const terms = form.querySelector('[data-po-terms]');
    const termFields = terms.querySelectorAll('[data-po-terms-supplier]');
    let previousGroup;
    function resizeField(field) {
        if (!field.getClientRects().length) return;
        field.style.height = 'auto';
        field.style.height = (field.scrollHeight + field.offsetHeight - field.clientHeight) + 'px';
    }
    const currency = new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR' });
    function render() {
        let total = 0;
        let ready = false;
        let invalid = false;
        const compared = new Set();
        const suppliers = new Set();
        for (const row of rows) {
            const visible = row.dataset.poGroup === group.value;
            row.hidden = !visible;
            for (const control of row.querySelectorAll('input, textarea')) control.disabled = !visible;
            if (!visible) continue;
            if (previousGroup !== group.value) row.querySelectorAll('[data-po-autogrow]').forEach(resizeField);
            if (row.dataset.compared) compared.add(row.dataset.compared);
            const qty = row.querySelector('[data-po-qty]');
            row.querySelector('[data-po-warning]').hidden = !qty.validity.rangeOverflow;
            const valid = qty.validity.valid && qty.valueAsNumber > 0;
            const amount = valid ? Math.round((qty.valueAsNumber * Number(row.dataset.rate) + Number.EPSILON) * 100) : 0;
            row.querySelector('[data-po-amount]').textContent = valid ? currency.format(amount / 100) : '';
            total += amount;
            if (qty.valueAsNumber > 0) suppliers.add(row.dataset.poSupplier);
            ready ||= valid;
            invalid ||= !qty.validity.valid;
        }
        const option = group.selectedOptions[0];
        for (const field of form.querySelectorAll('[data-po-detail]')) {
            if (field.dataset.poDetail === 'compared') {
                field.value = Array.from(compared).join(', ');
            } else if (previousGroup !== group.value) {
                field.value = option?.getAttribute('data-' + field.dataset.poDetail) ?? '';
            }
        }
        previousGroup = group.value;
        terms.hidden = suppliers.size === 0;
        for (const field of termFields) {
            field.hidden = !suppliers.has(field.dataset.poTermsSupplier);
            field.querySelector('textarea').disabled = field.hidden;
        }
        form.querySelector('[data-po-total]').textContent = currency.format(total / 100);
        const headerComplete = group.value && form.elements.employee.value
            && form.elements.due.value && form.elements.target_date.value;
        form.querySelector('[data-po-save]').disabled = !ready || invalid || !headerComplete;
    }
    form.addEventListener('change', event => {
        if (event.target.matches('[name="po_group"], [name="employee"], [name="due"], [name="target_date"]')) render();
    });
    form.addEventListener('input', event => {
        if (event.target.matches('[data-po-autogrow]')) resizeField(event.target);
        if (event.target.matches('[data-po-qty], [name="due"], [name="target_date"]')) render();
    });
    render();
})();
</script>
HTML;
        echo '</form>';
    }

    private static function purchaseOrderValues($rows,$row,$grouped=false) {
        $data = $row['data'];
        $indent = $rows[$data['indent'] ?? 0] ?? [];
        $details = self::supplierIndentDetails($indent,$rows);
        $supplier = self::supplierDisplay($data,$rows);
        $ref = $data['ref'] ?? '';
        $qty = $received = $open = 0;
        foreach ($grouped ? self::purchaseOrderGroup($rows,$row) : [$row] as $line) {
            $lineQty = (float)($line['data']['qty'] ?? 0);
            $lineReceived = self::sum($rows,'grn','po',$line['id'],'qty','approved');
            $qty += $lineQty;
            $received += $lineReceived;
            if ($line['status'] !== 'cancelled') {
                $open += max(0,$lineQty - $lineReceived - self::sum($rows,'cancel','po',$line['id']));
            }
        }
        return [
            'ref'=>$ref,'po_date'=>self::indentDisplayDate($data['po_date'] ?? ''),
            'so_no'=>$data['so_no'] ?? '', 'so_date'=>self::indentDisplayDate($data['so_date'] ?? ''),
            'timestamp'=>empty($data['po_timestamp']) ? '' : mysql2date('d/m/Y H:i:s',$data['po_timestamp'],false),
            'site_name'=>$details['site_name'],'supplier'=>$supplier,'rate'=>$data['rate'] ?? '',
            'qty'=>round($qty,3),'received'=>round($received,3),'open'=>round($open,3),
            'due'=>self::indentDisplayDate($data['due'] ?? ''),
            'requested'=>$indent['data']['requested_name'] ?? '',
            'amount'=>isset($data['rate']) ? number_format($data['amount'] ?? $qty * $data['rate'],2) : '',
            'po_supplier'=>implode(' · ',array_filter([$ref,$supplier],'strlen')),
            'brand'=>$data['brand'] ?? '', 'remarks'=>$data['remarks'] ?? '',
        ] + $details;
    }

    public static function downloadPurchaseOrderPdf() {
        $id = absint($_GET['id'] ?? 0);
        if (!self::canView('po')) {
            wp_die('Access denied.','Frontier',['response'=>403]);
        }
        check_admin_referer('frontier_po_pdf_'.$id);
        $rows = self::rows();
        $order = $rows[$id] ?? [];
        if (($order['kind'] ?? '') !== 'po') {
            wp_die('Purchase order not found.','Frontier',['response'=>404]);
        }
        $data = $order['data'];
        $values = self::purchaseOrderValues($rows,$order);
        $items = [];
        $total = 0;
        foreach (self::purchaseOrderGroup($rows,$order) as $line) {
            $item = self::purchaseOrderValues($rows,$line);
            $total += round($item['qty'] * (float)$item['rate'],2);
            $name = $item['item_name'];
            foreach (['brand'=>'Brand','remarks'=>'Remark'] as $field=>$label) {
                if ($item[$field] !== '') {
                    $name .= "\n".$label.': '.$item[$field];
                }
            }
            $items[] = [$item['line_number'],$name,$item['unit'],$item['qty'],$item['rate'],$item['amount']];
        }
        $details = [
            'PO Date'=>$values['po_date'],'Target Date'=>$values['due'],'Supplier'=>$values['supplier'],
            'Site'=>$values['site_name'],'Delivery Address'=>$data['delivery_address'] ?? '',
            'Site Contact'=>$data['delivery_contact'] ?? '', 'Site Mobile No.'=>$data['delivery_mobile'] ?? '',
        ];
        $pdf = Frontier_Boq_Pdf::buildPurchaseOrder($values['ref'],$details,$items,$total,$data['terms'] ?? '',self::purchaseOrderLogo());
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="purchase-order-'.$id.'.pdf"');
        header('Content-Length: '.strlen($pdf));
        echo $pdf;
        exit;
    }

    private static function purchaseOrderLogo() {
        $id = (int)get_option('frontier_logo_id',0);
        $path = $id ? get_attached_file($id) : '';
        if (!$path || !is_readable($path)) {
            return [];
        }
        $bytes = file_get_contents($path);
        if (!$bytes) {
            return [];
        }
        $logo = self::pdfJpegLogo($bytes);
        if ($logo && max($logo['width'],$logo['height']) <= 1200) {
            return $logo;
        }

        if (function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
            $source = @imagecreatefromstring($bytes);
            if ($source) {
                $width = imagesx($source);
                $height = imagesy($source);
                $scale = min(1,600/max($width,$height));
                $targetWidth = max(1,(int)round($width*$scale));
                $targetHeight = max(1,(int)round($height*$scale));
                $image = imagecreatetruecolor($targetWidth,$targetHeight);
                if ($image) {
                    imagefill($image,0,0,imagecolorallocate($image,255,255,255));
                    imagecopyresampled($image,$source,0,0,0,0,$targetWidth,$targetHeight,$width,$height);
                    ob_start();
                    try {
                        imagejpeg($image,null,90);
                        $converted = ob_get_contents();
                    } finally {
                        ob_end_clean();
                        imagedestroy($image);
                    }
                    $embedded = self::pdfJpegLogo($converted);
                    if ($embedded) {
                        imagedestroy($source);
                        return $embedded;
                    }
                }
                imagedestroy($source);
            }
        }

        require_once ABSPATH.'wp-admin/includes/file.php';
        $editor = wp_get_image_editor($path);
        if (is_wp_error($editor)) {
            return $logo;
        }
        $size = $editor->get_size();
        if (max($size['width'],$size['height']) > 600) {
            $editor->resize(600,600,false);
        }
        $temporary = wp_tempnam('frontier-po-logo');
        if (!$temporary) {
            return $logo;
        }
        $destination = $temporary.'.jpg';
        $output = '';
        try {
            $saved = $editor->save($destination,'image/jpeg');
            if (is_wp_error($saved)) {
                return $logo;
            }
            $output = $saved['path'];
            return self::pdfJpegLogo(file_get_contents($output)) ?: $logo;
        } finally {
            wp_delete_file($temporary);
            foreach (array_unique([$destination,$output]) as $file) {
                if ($file && is_file($file)) {
                    wp_delete_file($file);
                }
            }
        }
    }

    private static function pdfJpegLogo($bytes) {
        $image = $bytes ? @getimagesizefromstring($bytes) : false;
        $channels = (int)($image['channels'] ?? 3);
        if (!$image || $image[2] !== IMAGETYPE_JPEG || !in_array($channels,[1,3],true)) {
            return [];
        }
        return ['width'=>$image[0],'height'=>$image[1],'channels'=>$channels,'bytes'=>$bytes];
    }

    private static function purchaseOrderGroup($rows,$row) {
        $group = $row['data']['po_group_id'] ?? 0;
        if (!$group) {
            return [$row];
        }
        return array_filter($rows,static function ($line) use ($group) {
            return $line['kind'] === 'po' && ($line['data']['po_group_id'] ?? 0) === $group;
        });
    }

    private static function updatePurchaseOrderGroup($rows,$id,$action) {
        global $wpdb;
        $row = $rows[$id] ?? null;
        self::requireRule(($row['kind'] ?? '') === 'po','Purchase order not found.');
        foreach (self::purchaseOrderGroup($rows,$row) as $line) {
            if ($action === 'delete') {
                self::requireRule($line['status'] === 'draft' && !self::linked($rows,$line['id']),
                    'Only an unused draft PO may be deleted.');
                $result = $wpdb->delete(self::table(),['id'=>$line['id']]);
            } else {
                $result = $wpdb->update(self::table(),self::transition($rows,$line,$action),['id'=>$line['id']]);
            }
            if ($result !== 1) {
                throw new RuntimeException('Purchase order could not be updated.');
            }
        }
    }

    private static function purchaseOrderSupplierKey($quote) {
        $supplier = (int)($quote['data']['supplier'] ?? 0);
        return $supplier ? 'supplier-'.$supplier : 'quote-'.$quote['id'];
    }

    private static function savePurchaseOrders($rows) {
        global $wpdb;
        $group = self::input('po_group');
        $employee = $rows[absint(self::input('employee'))] ?? [];
        $date = self::input('due');
        $target = self::input('target_date');
        foreach ([$date,$target] as $value) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d',$value);
            self::requireRule($parsed && $parsed->format('Y-m-d') === $value,'Enter a valid PO Date and Target Date.');
        }
        self::requireRule(($employee['kind'] ?? '') === 'employee','Select Prepared By.');
        $submitted = $_POST['po_lines'] ?? [];
        self::requireRule(is_array($submitted),'Check the purchase order items.');
        $items = self::purchaseOrderItems($rows);
        $terms = $_POST['po_terms'] ?? [];
        self::requireRule(is_array($terms),'Check Supplier Terms.');
        $delivery = [];
        foreach (['address'=>5000,'contact'=>200,'mobile'=>200] as $field=>$limit) {
            $delivery['delivery_'.$field] = self::input('delivery_'.$field);
            self::requireRule(strlen($delivery['delivery_'.$field]) <= $limit,'Check the delivery details.');
        }
        $orderGroups = [];
        $year = self::fiscalYear($date);
        $prefix = self::poPrefix($year);
        $sequenceKey = 'frontier_po_sequence_'.$year;
        $sequence = (int)get_option($sequenceKey,0);
        foreach ($rows as $record) {
            $reference = $record['data']['ref'] ?? '';
            if ($record['kind'] === 'po' && strpos($reference,$prefix) === 0) {
                $sequence = max($sequence,(int)substr($reference,strlen($prefix)));
            }
        }
        $sequence = self::nextNumber('po',$sequence,$year)-1;
        $timestamp = current_time('mysql');
        foreach ($submitted as $quoteId=>$line) {
            self::requireRule(is_array($line) && is_string($line['qty'] ?? null),'Enter a valid Order Qty.');
            $qty = $line['qty'];
            if ($qty === '' || preg_match('/^0+(?:\.0+)?$/D',$qty)) {
                continue;
            }
            $item = $items[$quoteId] ?? null;
            self::requireRule($item && self::indentGroup($item['indent']) === $group,'This item is no longer available for this indent.');
            self::requireRule(preg_match('/^\d{1,10}(?:\.\d{1,3})?$/D',$qty) && (float)$qty <= $item['pending'],
                'Order Qty must be positive and cannot exceed Pending to Order.');
            $data = [
                'quote'=>(int)$quoteId,'qty'=>(float)$qty,'due'=>$target,'po_date'=>$date,'po_timestamp'=>$timestamp,
                'prepared_employee'=>(int)$employee['id'],'prepared_name'=>$employee['data']['name'],
            ];
            foreach (['brand','remarks'] as $field) {
                $value = $line[$field] ?? '';
                self::requireRule(is_string($value),'Check Brand and Remarks.');
                $data[$field] = sanitize_textarea_field(wp_unslash($value));
                self::requireRule(strlen($data[$field]) <= 200,'Brand and Remarks must be at most 200 bytes each.');
            }
            self::validateNew($rows,'po',$data);
            $data['amount'] = round($data['qty'] * $data['rate'],2);
            $quote = $item['quote'];
            $key = self::purchaseOrderSupplierKey($quote);
            $raw = $terms[$key] ?? ($rows[$quote['data']['supplier'] ?? 0]['data']['term'] ?? '');
            self::requireRule(is_string($raw),'Check Supplier Terms.');
            $data['terms'] = self::supplierText(wp_unslash($raw));
            self::requireRule(strlen($data['terms']) <= self::SUPPLIER_TEXT_LIMITS['term'],'Supplier Terms exceed the maximum size.');
            $data += $delivery;
            if (isset($orderGroups[$key])) {
                $data += $orderGroups[$key];
            }
            if ($wpdb->insert(self::table(),['kind'=>'po','status'=>'draft','details'=>wp_json_encode($data)]) !== 1) {
                throw new RuntimeException('Purchase order could not be saved.');
            }
            if (!isset($orderGroups[$key])) {
                $id = (int)$wpdb->insert_id;
                $orderGroups[$key] = ['po_group_id'=>$id,'ref'=>$prefix.str_pad((string)++$sequence,4,'0',STR_PAD_LEFT)];
                if ($wpdb->update(self::table(),['details'=>wp_json_encode($data + $orderGroups[$key])],['id'=>$id]) !== 1) {
                    throw new RuntimeException('Purchase order number could not be saved.');
                }
            }
        }
        self::requireRule($orderGroups,'Enter an Order Qty for at least one item.');
        update_option($sequenceKey,$sequence,false);
    }

    private static function supplierIndentDetails($indent,$rows) {
        $data = $indent['data'] ?? [];
        $boq = $rows[$data['boq'] ?? 0]['data'] ?? [];
        $site = $rows[$data['site'] ?? ($boq['site'] ?? 0)]['data'] ?? [];
        return [
            'site_name'=>self::siteNumberWithName($site),
            'indent_number'=>$data['indent_number'] ?? (isset($indent['id']) ? '#'.$indent['id'] : ''),
            'indent_date'=>self::indentDisplayDate($data['indent_date'] ?? ''),
            'line_number'=>$data['line_number'] ?? ($data ? 1 : ''),
            'item_name'=>$boq['name'] ?? '',
            'qty'=>$data['qty'] ?? '',
            'unit'=>$data['unit'] ?? $boq['unit'] ?? '',
        ];
    }

    private static function supplierChoices($rows,$includeFinalized=false) {
        $choices = [];
        $finalized = [];
        foreach ($rows as $quote) {
            if ($quote['kind'] !== 'quote') {
                continue;
            }
            $data = $quote['data'];
            $indentId = (int)$data['indent'];
            if ($quote['status'] === 'selected') {
                $finalized[$indentId] = true;
            }
            if (($includeFinalized || ($quote['status'] !== 'selected' && ($rows[$indentId]['status'] ?? '') === 'approved'))
                && isset($data['supplier'],$data['rate'])) {
                $choices[$indentId][$data['supplier']] = $choices[$indentId][$data['supplier']] ?? $quote;
            }
        }
        return $includeFinalized ? $choices : array_diff_key($choices,$finalized);
    }

    private static function chooseSupplierForm($rows) {
        $groups = [];
        foreach (self::supplierChoices($rows) as $indentId=>$choices) {
            $indent = $rows[$indentId];
            $groups[self::indentGroup($indent)][$indentId] = array_slice($choices,0,3);
        }
        $source = $rows[absint($_GET['source'] ?? 0)] ?? [];
        $indent = $rows[$source['data']['indent'] ?? 0] ?? null;
        $prefill = $indent ? self::indentGroup($indent) : '';
        if (!$groups) {
            echo '<p>No items are awaiting supplier selection.</p>';
            return;
        }
        self::formStart('quote','save_supplier_selection');
        echo '<div class="frontier-fields">';
        self::field($rows,'quote','due','Date',current_time('Y-m-d'));
        echo '<p><label>'.self::requiredLabel('Indent No. with Site Name').'<select name="selection_group" required><option value="">Select</option>';
        foreach ($groups as $group=>$items) {
            $first = $rows[array_key_first($items)];
            $data = $first['data'];
            $boq = $rows[$data['boq'] ?? 0]['data'] ?? [];
            $site = $rows[$data['site'] ?? ($boq['site'] ?? 0)]['data'] ?? [];
            $label = ($data['indent_number'] ?? '#'.$first['id']).' · '.self::siteNumberWithName($site);
            echo '<option value="'.esc_attr($group).'" '.selected($prefill,$group,false).'>'.esc_html($label).'</option>';
        }
        echo '</select></label></p>';
        self::field($rows,'quote','employee','Compared By','');
        echo '</div><h2>Indent Items</h2><div class="frontier-table frontier-master-table" tabindex="0" role="region" aria-label="Supplier selection items" style="overflow-x:auto">';
        echo '<table class="widefat striped" style="min-width:1240px"><thead><tr>';
        $columns = ['Sl No.','Item','Qty','Supplier 1','Rate 1','Supplier 2','Rate 2','Supplier 3','Rate 3','Supplier Finalized','Rate Finalized'];
        $colors = [3=>'#dfeeff',4=>'#dfeeff',5=>'#dcf4ef',6=>'#dcf4ef',7=>'#ede6fb',8=>'#ede6fb'];
        foreach ($columns as $index=>$label) {
            $style = isset($colors[$index]) ? ' style="background:'.$colors[$index].'"' : '';
            echo '<th scope="col"'.$style.'>'.esc_html($label).'</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($groups as $group=>$items) {
            uasort($items,static function ($left,$right) use ($rows) {
                return ($rows[$left[0]['data']['indent']]['data']['line_number'] ?? 1)
                    <=> ($rows[$right[0]['data']['indent']]['data']['line_number'] ?? 1);
            });
            foreach ($items as $indentId=>$choices) {
                $data = $rows[$indentId]['data'];
                $name = $rows[$data['boq'] ?? 0]['data']['name'] ?? '';
                echo '<tr data-choice-group="'.esc_attr($group).'" style="display:none">';
                foreach ([$data['line_number'] ?? 1,$name,$data['qty'] ?? ''] as $value) {
                    echo '<td>'.esc_html((string)$value).'</td>';
                }
                for ($index = 0; $index < 3; $index++) {
                    $quoteData = $choices[$index]['data'] ?? [];
                    echo '<td>'.esc_html(self::supplierDisplay($quoteData,$rows)).'</td><td>'.esc_html((string)($quoteData['rate'] ?? '')).'</td>';
                }
                echo '<td><select data-supplier-choice data-dropdown-overlay aria-label="'.esc_attr('Supplier finalized for '.$name).'" name="supplier_choices['.esc_attr($indentId).']" disabled><option value="">Select supplier</option>';
                foreach ($choices as $quote) {
                    echo '<option value="'.esc_attr($quote['id']).'" data-rate="'.esc_attr($quote['data']['rate']).'">'.esc_html(self::supplierDisplay($quote['data'],$rows)).'</option>';
                }
                echo '</select></td><td><output aria-label="Rate finalized"></output></td></tr>';
            }
        }
        echo '</tbody></table></div><p><a class="button" href="'.esc_url(self::url('pending_choose')).'">Cancel</a> ';
        echo '<button type="submit" class="button button-primary" data-save-choice disabled>Save Supplier Selection</button></p>';
        echo <<<'HTML'
<script>
(() => {
    const form = document.currentScript.closest('form');
    const group = form.elements.selection_group;
    const rows = Array.from(form.querySelectorAll('[data-choice-group]'));
    const save = form.querySelector('[data-save-choice]');
    const render = () => {
        let hasSelection = false;
        for (const row of rows) {
            const choice = row.querySelector('[data-supplier-choice]');
            const visible = row.dataset.choiceGroup === group.value;
            row.style.display = visible ? '' : 'none';
            choice.disabled = !visible;
            if (visible && !choice.hidden) choice.dispatchEvent(new Event('frontier:enhance-dropdown', { bubbles: true }));
            row.querySelector('output').textContent = choice.selectedOptions[0]?.dataset.rate ?? '';
            hasSelection ||= visible && choice.value !== '';
        }
        save.disabled = !hasSelection;
    };
    form.addEventListener('change', event => {
        if (event.target === group || event.target.matches('[data-supplier-choice]')) render();
    });
    render();
})();
</script>
HTML;
        echo '</form>';
    }

    private static function saveSupplierSelection($rows) {
        global $wpdb;
        $group = self::input('selection_group');
        $employee = $rows[absint(self::input('employee'))] ?? null;
        $dateText = self::input('due');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d',$dateText);
        self::requireRule(($employee['kind'] ?? '') === 'employee','Select Compared By.');
        self::requireRule($date && $date->format('Y-m-d') === $dateText,'Enter a valid selection date.');
        $submitted = $_POST['supplier_choices'] ?? [];
        self::requireRule(is_array($submitted),'Check the supplier selections.');
        $choices = self::supplierChoices($rows);
        $saved = false;
        foreach ($submitted as $indentId=>$quoteId) {
            if ($quoteId === '') {
                continue;
            }
            $quote = is_string($quoteId) && ctype_digit($quoteId) ? ($rows[(int)$quoteId] ?? null) : null;
            $supplierId = $quote['data']['supplier'] ?? 0;
            $eligible = array_slice($choices[$indentId] ?? [],0,3,true);
            self::requireRule($quote && isset($eligible[$supplierId]) && (int)$eligible[$supplierId]['id'] === (int)$quoteId
                && self::indentGroup($rows[$indentId]) === $group,'This supplier selection is no longer available. Reload the form.');
            $data = $quote['data'];
            $data['selection_date'] = $dateText;
            $data['compared_at'] = current_time('mysql');
            $data['compared_by'] = $employee['data']['name'];
            $data['compared_employee'] = (int)$employee['id'];
            if ($wpdb->update(self::table(),['status'=>'selected','details'=>wp_json_encode($data)],['id'=>$quote['id']]) !== 1) {
                throw new RuntimeException('Supplier selection could not be saved.');
            }
            $saved = true;
        }
        self::requireRule($saved,'Select a supplier for at least one item.');
    }

    private static function quotationForm($rows) {
        $groups = [];
        $quoted = [];
        $suppliers = [];
        foreach ($rows as $row) {
            $data = $row['data'];
            if ($row['kind'] === 'supplier') {
                $suppliers[] = ['id'=>(int)$row['id'],'label'=>self::supplierDisplay(['supplier'=>$row['id']],$rows)];
            } elseif ($row['kind'] === 'quote') {
                $quoted[(int)$data['indent']] = true;
            }
        }
        foreach (array_reverse($rows) as $row) {
            if ($row['kind'] !== 'indent' || $row['status'] !== 'approved') {
                continue;
            }
            $data = $row['data'];
            $id = (int)$row['id'];
            if (isset($quoted[$id])) {
                continue;
            }
            $group = self::indentGroup($row);
            $boq = $rows[$data['boq']]['data'] ?? [];
            $site = $rows[$data['site'] ?? ($boq['site'] ?? 0)]['data'] ?? [];
            $number = $data['indent_number'] ?? '#'.$id;
            if (!isset($groups[$group])) {
                $groups[$group] = ['label'=>$number.' · '.self::siteNumberWithName($site),'items'=>[]];
            }
            $line = $data['line_number'] ?? 1;
            $past = self::lastIssuedPurchase($rows,$boq,$id);
            $groups[$group]['items'][] = ['id'=>$id,'line'=>$line,'number'=>$number.'/'.$line,
                'name'=>$boq['name'] ?? '', 'qty'=>$data['qty'],'locked'=>false,
                'lastSupplier'=>$past ? self::supplierDisplay($past,$rows) : '', 'lastRate'=>$past['rate'] ?? '',
                'quotes'=>[]];
        }
        $source = $rows[absint($_GET['source'] ?? 0)] ?? null;
        $prefill = $source && $source['kind'] === 'indent' ? self::indentGroup($source) : '';
        self::formStart('quote','save_quotations');
        echo '<input type="hidden" name="quotation_lines"><div class="frontier-fields">';
        self::field($rows,'quote','due','Date',current_time('Y-m-d'));
        echo '<p><label>'.self::requiredLabel('Indent No. with Site Name').'<select name="quotation_group" required><option value="">Select</option>';
        foreach (array_reverse($groups,true) as $key=>$group) {
            echo '<option value="'.esc_attr($key).'" '.selected($prefill,$key,false).'>'.esc_html($group['label']).'</option>';
        }
        echo '</select></label></p>';
        self::field($rows,'quote','employee','Compared By','');
        echo '</div><h2>Indent Items</h2><p class="frontier-quotation-message" role="status"></p>';
        echo '<div class="frontier-table" tabindex="0" role="region" aria-label="Supplier quotation items"><table class="widefat striped"><thead><tr>';
        foreach (['Sl No.','Item','Qty','Last Supplier','Last Supplier Rate','Supplier 1','Rate 1',
            'Supplier 2','Rate 2','Supplier 3','Rate 3'] as $label) {
            echo '<th scope="col">'.esc_html($label).'</th>';
        }
        echo '</tr></thead><tbody></tbody></table></div><datalist id="frontier-quote-suppliers"></datalist>';
        echo '<script type="application/json" class="frontier-quotation-data">'.wp_json_encode(['groups'=>$groups,'suppliers'=>$suppliers],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).'</script>';
        echo '<p><a class="button" href="'.esc_url(self::url('quote')).'">Cancel</a> ';
        echo '<button type="submit" class="button button-primary frontier-quotation-save" disabled>Save Quotations</button></p>';
        echo '<noscript><p>Enable JavaScript to enter supplier rates.</p></noscript></form>';
    }

    private static function saveQuotations($rows) {
        global $wpdb;
        $group = self::input('quotation_group');
        $employee = $rows[absint(self::input('employee'))] ?? null;
        $dateText = self::input('due');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d',$dateText);
        self::requireRule($employee && $employee['kind'] === 'employee','Select Compared By.');
        self::requireRule($date && $date->format('Y-m-d') === $dateText,'Enter a valid quotation date.');
        $raw = $_POST['quotation_lines'] ?? '';
        $lines = is_string($raw) && strlen($raw) <= 200000 ? json_decode(wp_unslash($raw),true) : null;
        self::requireRule(is_array($lines) && count($lines) > 0 && count($lines) <= 300,'Enter three supplier rates per item.');
        $counts = [];
        foreach ($lines as $line) {
            self::requireRule(is_array($line) && is_int($line['indent'] ?? null),'Check the quotation entries.');
            $indentId = $line['indent'];
            $counts[$indentId] = ($counts[$indentId] ?? 0) + 1;
        }
        foreach ($counts as $count) {
            self::requireRule($count === 3,'Every item being quoted must include three suppliers and three rates.');
        }
        $quoted = [];
        foreach ($rows as $row) {
            if ($row['kind'] === 'quote') {
                $quoted[(int)$row['data']['indent']] = true;
            }
        }
        $now = current_time('mysql');
        $seen = [];
        foreach ($lines as $line) {
            $indentId = $line['indent'] ?? 0;
            $supplierId = $line['supplier'] ?? 0;
            $rate = $line['rate'] ?? '';
            self::requireRule(is_int($indentId) && is_int($supplierId) && is_string($rate),'Check the quotation entries.');
            $indent = $rows[$indentId] ?? null;
            $supplier = $rows[$supplierId] ?? null;
            self::requireRule($indent && $indent['kind'] === 'indent' && $indent['status'] === 'approved'
                && self::indentGroup($indent) === $group && !isset($quoted[$indentId]),
                'Only approved items from this indent that have not been quoted can be submitted.');
            self::requireRule($supplier && $supplier['kind'] === 'supplier','Choose a supplier from Supplier Master.');
            self::requireRule(preg_match('/^\d{1,10}(?:\.\d{1,3})?$/D',$rate) && (float)$rate >= 0,'Enter a valid supplier rate.');
            self::requireRule(!isset($seen[$indentId][$supplierId]) && count($seen[$indentId] ?? []) < 3,
                'Enter three distinct suppliers per item.');
            $seen[$indentId][$supplierId] = true;
            $data = [
                'indent'=>$indentId,'supplier'=>$supplierId,'name'=>$supplier['data']['name'],'rate'=>(float)$rate,
                'quote_date'=>$dateText,'comparison_employee'=>(int)$employee['id'],
                'comparison_name'=>$employee['data']['name'],
            ];
            $data['quote_timestamp'] = $now;
            $result = $wpdb->insert(self::table(),['kind'=>'quote','status'=>'received','details'=>wp_json_encode($data)]);
            if ($result === false) {
                throw new RuntimeException('Supplier quotations could not be saved.');
            }
        }
    }

    private static function updateIndentGroup($rows,$id) {
        global $wpdb;
        $anchor = $rows[$id] ?? null;
        self::requireRule($anchor && $anchor['kind'] === 'indent','Indent not found.');
        $group = self::indentGroup($anchor);
        $status = self::input('approval_status');
        $remarks = self::input('approval_remarks');
        self::requireRule(in_array($status,['approved','rejected'],true),'Choose Approved or Rejected.');
        self::requireRule(strlen($remarks) <= 2000,'Enter remarks of up to 2,000 bytes.');
        $actor = wp_get_current_user()->display_name;
        $time = current_time('mysql');
        foreach ($rows as $row) {
            if ($row['kind'] !== 'indent' || $row['status'] !== 'pending' || self::indentGroup($row) !== $group) {
                continue;
            }
            $data = $row['data'];
            $data['decision_at'] = $time;
            $data['decision_by'] = $actor;
            $data['reason'] = $remarks;
            $update = ['status'=>$status,'details'=>wp_json_encode($data)];
            if ($wpdb->update(self::table(),$update,['id'=>$row['id']]) !== 1) {
                throw new RuntimeException('Indent update could not be saved.');
            }
        }
    }

    public static function save() {
        global $wpdb;
        check_admin_referer('frontier_save');
        $kind = sanitize_key(self::input('kind'));
        $action = sanitize_key(self::input('operation'));
        $capability = ['approve'=>'approve','update_indent_group'=>'approve','return'=>'approve','approve_grn'=>'stock'][$action] ?? ($kind === 'site' ? 'sites' : $kind);
        $screen = sanitize_key(self::input('screen'));
        $sameKind = $screen === $kind || (self::SCREENS[$screen][0] ?? '') === $kind;
        $access = self::assignedViewAccess(get_current_user_id());
        $configured = !current_user_can('manage_options') && is_array($access) && isset($access['role']);
        $allowedView = $configured ? ($sameKind && self::canView($screen)) : self::canView($kind);
        if (!isset(self::FIELDS[$kind]) || !$allowedView || !self::can($capability)) {
            wp_die('Access denied.', '', ['response'=>403]);
        }
        $lock = 'frontier_'.md5(DB_NAME.'|'.self::table());
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)',$lock)) !== 1) {
            wp_die('Frontier is busy. Please retry.', '', ['response'=>503]);
        }
        $error = null;
        $transaction = false;
        $accountId = 0;
        $imported = null;
        try {
            $rows = self::rows();
            if (($kind === 'employee' && in_array($action,['create','edit','import'],true))
                || ($kind === 'item' && $action === 'import_items')
                || ($kind === 'match' && $action === 'link_po_invoice')
                || ($kind === 'grn' && in_array($action,['create','create_goods_receipt','approve_grn'],true))
                || ($kind === 'po' && in_array($action,['create_purchase_orders','issue','delete','cancel_po'],true))
                || ($kind === 'quote' && in_array($action,['save_quotations','save_supplier_selection'],true))
                || ($kind === 'supplier' && $action === 'import_suppliers')
                || ($kind === 'indent' && in_array($action,['create_indent','update_indent_group'],true))
                || ($kind === 'boq' && in_array($action,['create_boq','edit_boq','delete_boq'],true))) {
                if ($wpdb->query('START TRANSACTION') === false) {
                    throw new RuntimeException('Database transaction could not start.');
                }
                $transaction = true;
            }
            if ($kind === 'quote' && $action === 'save_quotations') {
                self::saveQuotations($rows);
                $result = true;
            } elseif ($kind === 'match' && $action === 'link_po_invoice') {
                self::savePurchaseInvoiceLinks($rows);
                $result = true;
            } elseif ($kind === 'grn' && $action === 'approve_grn') {
                $receipt = $rows[absint(self::input('id'))] ?? [];
                self::requireRule(($receipt['kind'] ?? '') === 'grn','Goods receipt not found.');
                $result = false;
                foreach (self::goodsReceiptGroup($rows,$receipt) as $line) {
                    if ($line['status'] === 'pending') {
                        if ($wpdb->update(self::table(),self::transition($rows,$line,$action),['id'=>$line['id']]) !== 1) {
                            throw new RuntimeException('Goods receipt approval could not be saved.');
                        }
                        $result = true;
                    }
                }
                self::requireRule($result,'This GRN has already been processed.');
            } elseif ($kind === 'grn' && $action === 'create_goods_receipt') {
                self::saveGoodsReceipt($rows);
                $result = true;
            } elseif ($kind === 'po' && $action === 'create_purchase_orders') {
                self::savePurchaseOrders($rows);
                $result = true;
            } elseif ($kind === 'po' && in_array($action,['issue','delete','cancel_po'],true)) {
                self::updatePurchaseOrderGroup($rows,absint(self::input('id')),$action);
                $result = true;
            } elseif ($kind === 'quote' && $action === 'save_supplier_selection') {
                self::saveSupplierSelection($rows);
                $result = true;
            } elseif ($kind === 'indent' && $action === 'update_indent_group') {
                self::updateIndentGroup($rows,absint(self::input('id')));
                $result = true;
            } elseif ($kind === 'indent' && $action === 'create_indent') {
                self::saveIndent($rows);
                $result = true;
            } elseif ($kind === 'boq' && in_array($action,['create_boq','edit_boq','delete_boq'],true)) {
                $id = absint(self::input('id'));
                self::requireRule($action === 'create_boq' || $id,'BOQ not found.');
                if ($action === 'delete_boq') {
                    $lines = self::boqLines($rows,$id);
                    self::requireRule(!array_intersect_key(self::boqReferences($rows),$lines),'A BOQ used in an indent cannot be deleted.');
                    $number = (int)($rows[$id]['data']['boq_number'] ?? 0);
                    if ($number > (int)get_option('frontier_last_deleted_boq',0)) {
                        update_option('frontier_last_deleted_boq',$number,false);
                    }
                    foreach ($lines as $line) {
                        if ($wpdb->delete(self::table(),['id'=>$line['id']]) !== 1) {
                            throw new RuntimeException('BOQ could not be deleted.');
                        }
                    }
                } else {
                    self::saveBoq($rows,$action === 'edit_boq' ? $id : 0);
                }
                $result = true;
            } elseif ($kind === 'supplier' && $action === 'import_suppliers') {
                $imported = self::importSuppliers($rows);
                $result = true;
            } elseif ($kind === 'item' && $action === 'import_items') {
                $imported = self::importItems($rows);
                $result = true;
            } elseif ($kind === 'employee' && $action === 'import') {
                $imported = self::importEmployees($rows);
                $result = true;
            } elseif ($action === 'create') {
                $data = self::readFields($rows,$kind);
                if (in_array($kind,['site','supplier'],true)) {
                    $data['ref'] = self::formatMasterCode(self::nextNumber($kind,self::lastMasterCode($rows,$kind)));
                }
                self::validateNew($rows,$kind,$data);
                if ($kind === 'employee') {
                    $accountId = self::employeeAccount($data);
                    $data['wp_user_id'] = $accountId;
                }
                $status = ['indent'=>'draft','po'=>'draft','grn'=>'pending','quote'=>'received','invoice'=>'pending_match'][$kind] ?? 'recorded';
                if ($kind === 'grn') {
                    $data['grn_number'] = self::nextNumber('grn',self::lastNumbers($rows,0)['grn']);
                }
                $document = isset(self::FILES[$kind]) ? self::document($kind === 'invoice') : [];
                $result = $wpdb->insert(self::table(),$document + ['kind'=>$kind,'status'=>$status,'details'=>wp_json_encode($data)]);
                if ($result === 1 && $kind === 'grn' && $rows[$data['po']]['status'] === 'draft') {
                    self::updatePurchaseOrderGroup($rows,$data['po'],'issue');
                }
            } else {
                $row = $rows[absint(self::input('id'))] ?? null;
                self::requireRule($row && $row['kind'] === $kind,'Record could not be found.');
                if ($action === 'delete_master') {
                    self::requireRule(in_array($kind,['employee','item','units'],true),'This record cannot be deleted here.');
                    if ($kind === 'employee') {
                        self::requireRule(empty($row['data']['wp_user_id']),'This employee has a linked WordPress login. Resolve the login before deleting the record.');
                        foreach ($rows as $record) {
                            self::requireRule($record['kind'] !== 'site' || (int)($record['data']['employee'] ?? 0) !== (int)$row['id'],
                                'Reassign this employee’s sites before deleting the record.');
                        }
                    }
                    $result = $wpdb->delete(self::table(),['id'=>$row['id']]);
                } else {
                    $update = self::transition($rows,$row,$action);
                    if ($transaction) {
                        $data = json_decode($update['details'],true);
                        $accountId = self::employeeAccount($data);
                        $data['wp_user_id'] = $accountId;
                        $update['details'] = wp_json_encode($data);
                        $update['status'] = 'recorded';
                    }
                    $result = $wpdb->update(self::table(),$update,['id'=>$row['id']]);
                }
            }
            if ($result === false) {
                throw new RuntimeException('The record could not be saved.');
            }
            if ($transaction && $wpdb->query('COMMIT') === false) {
                throw new RuntimeException('Database transaction could not finish.');
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
        $destination = $action !== 'create_boq' && $sameKind && self::canView($screen) ? $screen : $kind;
        if ($action === 'create_indent') {
            $destination = 'indent_master';
        } elseif ($action === 'save_quotations') {
            $destination = 'quote';
        } elseif ($action === 'save_supplier_selection') {
            $destination = 'choose_master';
        } elseif ($action === 'create_purchase_orders') {
            $destination = 'po';
        } elseif ($action === 'create_goods_receipt') {
            $destination = 'grn';
        }
        $query = $imported === null ? [in_array($action,['delete_master','delete_boq'],true) ? 'deleted' : 'saved'=>1] : ['imported'=>$imported];
        if ($kind === 'indent' && isset($row) && ($screen === 'indent_items' || $action === 'edit')) {
            $destination = 'indent_items';
            $query['indent_group'] = self::indentGroup($row);
        }
        wp_safe_redirect(add_query_arg($query,self::url($destination)));
        exit;
    }
}

register_activation_hook(__FILE__,[Frontier::class,'activate']);
Frontier::boot();
