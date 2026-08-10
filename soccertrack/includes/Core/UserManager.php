<?php
/**
 * Gestión de usuarios del plugin SoccerTrack.
 *
 * Crea, lista y elimina usuarios con roles del plugin.
 *
 * @package SoccerTrack
 */

declare(strict_types=1);

namespace SportsLeague\Core;

final class UserManager {

	/** @var array<string, string> rol slug → etiqueta legible */
	public const PLUGIN_ROLES = [
		'ds_coordinador' => 'Coordinador de Liga',
		'ds_veedor'      => 'Veedor de Resultados',
	];

	/**
	 * Crea un usuario de WordPress con el rol de plugin indicado.
	 *
	 * @param string $email        Correo electrónico (único).
	 * @param string $display_name Nombre para mostrar.
	 * @param string $role         Slug del rol (ds_coordinador | ds_veedor).
	 * @param string $password     Contraseña en texto plano.
	 * @return int|\WP_Error       ID del usuario creado, o WP_Error si falla.
	 */
	public static function create(
		string $email,
		string $display_name,
		string $role,
		string $password
	): int|\WP_Error {
		if ( ! array_key_exists( $role, self::PLUGIN_ROLES ) ) {
			return new \WP_Error( 'invalid_role', __( 'Rol no válido.', 'soccertrack' ) );
		}

		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'invalid_email', __( 'Correo no válido.', 'soccertrack' ) );
		}

		if ( email_exists( $email ) ) {
			return new \WP_Error( 'email_exists', __( 'Ya existe un usuario con ese correo.', 'soccertrack' ) );
		}

		$username = sanitize_user(
			strtolower( explode( '@', $email )[0] ) . '_' . wp_generate_password( 4, false ),
			true
		);

		$user_id = wp_create_user( $username, $password, $email );

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$user = new \WP_User( $user_id );
		$user->set_role( $role );

		wp_update_user( [
			'ID'           => $user_id,
			'display_name' => sanitize_text_field( $display_name ),
		] );

		return $user_id;
	}

	/**
	 * Elimina un usuario del plugin.
	 * No permite eliminar administradores ni al usuario actual.
	 *
	 * @param int $user_id ID del usuario a eliminar.
	 * @return bool True si fue eliminado.
	 */
	public static function delete( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		if ( $user_id === get_current_user_id() ) {
			return false;
		}

		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return false;
		}

		if ( in_array( 'administrator', (array) $user->roles, true ) ) {
			return false;
		}

		$has_plugin_role = ! empty( array_intersect(
			(array) $user->roles,
			array_keys( self::PLUGIN_ROLES )
		) );

		if ( ! $has_plugin_role ) {
			return false;
		}

		require_once ABSPATH . 'wp-admin/includes/user.php';
		return wp_delete_user( $user_id ) !== false;
	}

	/**
	 * Lista todos los usuarios con roles del plugin.
	 *
	 * @return array<int, array{id:int, name:string, email:string, role:string, role_label:string, registered:string}>
	 */
	public static function list_plugin_users(): array {
		$wp_users = get_users( [
			'role__in' => array_keys( self::PLUGIN_ROLES ),
			'orderby'  => 'registered',
			'order'    => 'DESC',
		] );

		$result = [];
		foreach ( $wp_users as $user ) {
			$roles      = (array) $user->roles;
			$role_slug  = $roles[0] ?? '';
			$result[]   = [
				'id'         => $user->ID,
				'name'       => $user->display_name,
				'email'      => $user->user_email,
				'role'       => $role_slug,
				'role_label' => self::PLUGIN_ROLES[ $role_slug ] ?? $role_slug,
				'registered' => $user->user_registered,
			];
		}

		return $result;
	}
}
