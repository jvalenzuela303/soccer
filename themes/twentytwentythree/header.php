<?php
/**
 * Header para twentytwentythree (block/FSE theme).
 * WordPress requiere este archivo o genera un aviso de deprecated.
 * Emite el HTML de apertura estándar con wp_head() para que los plugins
 * puedan encolar sus assets correctamente via wp_enqueue_scripts.
 */
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
