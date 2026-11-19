<?php
defined('ABSPATH') || exit;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html(get_the_title(get_queried_object_id())); ?> · Frontier</title>
    <?php wp_head(); ?>
</head>
<body <?php body_class('frontier-workspace'); ?>>
<?php wp_body_open(); ?>
<?php Frontier::workspace(); ?>
<?php wp_footer(); ?>
</body>
</html>
