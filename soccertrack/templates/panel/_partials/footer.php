</main>

<footer class="st-panel-footer">
	<p>
		<?php
		printf(
			/* translators: %d year */
			esc_html__( '© %d SoccerTrack — Panel interno', 'soccertrack' ),
			(int) gmdate( 'Y' )
		);
		?>
	</p>
</footer>

<script src="<?php echo esc_url( SOCCERTRACK_URL . 'assets/js/panel.js?v=' . SOCCERTRACK_VERSION ); ?>"></script>
</body>
</html>
