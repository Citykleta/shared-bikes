<?php
/**
 * "Scroll Story" widget: a story told in scenes inside one card, driven by the
 * scroll (the "Comunidad" tab of the home page).
 *
 * Scenes, in order:
 *   1. Cover — a photo shown whole, with its text on the left.
 *   2. Slides — the repeater. The cover photo turns into the first slide's image:
 *      it zooms in on the person (top edge fixed) while its window closes to the
 *      left half. From there images alternate sides (left, right, left…), each
 *      sliding in through its own side, with the text in the free half.
 *   3. Closing — a photo over the whole card with its text centred at the bottom.
 * Every text comes up from below the card and leaves over the top as you scroll.
 *
 * The card scrolls on its own (an inner scroller); when the story ends the page
 * takes over. citykleta-story.js does the animation. Without JS the scenes are
 * simply listed one under another; with prefers-reduced-motion they switch
 * without moving.
 *
 * In a title, a word wrapped in *asterisks* gets the yellow highlight.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Citykleta_Story_Widget.
 */
class Citykleta_Story_Widget extends \Elementor\Widget_Base {

	/**
	 * Internal widget name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'citykleta_story';
	}

	/**
	 * Title shown in the Elementor editor.
	 *
	 * @return string
	 */
	public function get_title() {
		return 'Scroll Story';
	}

	/**
	 * Widget icon in the Elementor panel.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-slides';
	}

	/**
	 * Category the widget appears under in the widgets panel.
	 *
	 * @return array
	 */
	public function get_categories() {
		return array( 'citykleta' );
	}

	/**
	 * Keywords used to find it when searching in Elementor.
	 *
	 * @return array
	 */
	public function get_keywords() {
		return array( 'story', 'historia', 'scroll', 'slides', 'community', 'comunidad', 'citykleta' );
	}

	/**
	 * Controls shown in Elementor's left panel.
	 */
	protected function register_controls() {
		$this->register_cover_controls();
		$this->register_slides_controls();
		$this->register_closing_controls();
		$this->register_settings_controls();
		$this->register_card_style_controls();
		$this->register_text_style_controls();
		$this->register_closing_style_controls();
	}

	/**
	 * Content → Cover.
	 */
	private function register_cover_controls() {
		$this->start_controls_section( 'section_cover', array( 'label' => 'Cover' ) );

		$this->add_control(
			'cover_image',
			array(
				'label'       => 'Photo',
				'type'        => \Elementor\Controls_Manager::MEDIA,
				'default'     => array( 'url' => CITYKLETA_URL . 'assets/images/story/cover.webp' ),
				'description' => 'Shown whole on the cover, then zoomed in on the person as the image of the first slide. A cut-out on white works best.',
			)
		);

		$this->add_control(
			'cover_kicker',
			array(
				'label'   => 'Kicker',
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => 'Comunidad',
			)
		);

		$this->add_control(
			'cover_title',
			array(
				'label'       => 'Title',
				'type'        => \Elementor\Controls_Manager::TEXTAREA,
				'rows'        => 2,
				'default'     => 'Con Citykleta, cada persona aporta a la ciudad en *bici*',
				'description' => 'Wrap a word in *asterisks* to highlight it.',
			)
		);

		$this->add_control(
			'cover_text',
			array(
				'label'   => 'Text',
				'type'    => \Elementor\Controls_Manager::TEXTAREA,
				'rows'    => 3,
				'default' => '',
			)
		);

		$this->add_control(
			'person_heading',
			array(
				'label'     => 'Person In The Photo',
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'person_left',
			array(
				'label'       => 'Left Edge (%)',
				'type'        => \Elementor\Controls_Manager::SLIDER,
				'range'       => array( '%' => array( 'min' => 0, 'max' => 100 ) ),
				'size_units'  => array( '%' ),
				'default'     => array( 'unit' => '%', 'size' => 44 ),
				'description' => 'Where the person starts and ends across the photo. That band fills the half of the card in the first slide.',
			)
		);

		$this->add_control(
			'person_right',
			array(
				'label'      => 'Right Edge (%)',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'range'      => array( '%' => array( 'min' => 0, 'max' => 100 ) ),
				'size_units' => array( '%' ),
				'default'    => array( 'unit' => '%', 'size' => 72 ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Content → Slides: the repeater.
	 */
	private function register_slides_controls() {
		$this->start_controls_section( 'section_slides', array( 'label' => 'Slides' ) );

		$repeater = new \Elementor\Repeater();

		$repeater->add_control(
			'image',
			array(
				'label'       => 'Photo',
				'type'        => \Elementor\Controls_Manager::MEDIA,
				'default'     => array( 'url' => '' ),
				'description' => 'Fills half of the card. Ignored on the first slide, which shows the cover photo.',
			)
		);

		$repeater->add_control(
			'focus',
			array(
				'label'   => 'Photo Focus',
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => '50% 50%',
				'options' => array(
					'50% 0%'   => 'Top',
					'50% 50%'  => 'Center',
					'50% 100%' => 'Bottom',
					'0% 50%'   => 'Left',
					'100% 50%' => 'Right',
				),
			)
		);

		$repeater->add_control(
			'kicker',
			array(
				'label'   => 'Kicker',
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => '',
			)
		);

		$repeater->add_control(
			'title',
			array(
				'label'       => 'Title',
				'type'        => \Elementor\Controls_Manager::TEXTAREA,
				'rows'        => 2,
				'default'     => '',
				'description' => 'Wrap a word in *asterisks* to highlight it.',
			)
		);

		$repeater->add_control(
			'text',
			array(
				'label'   => 'Text',
				'type'    => \Elementor\Controls_Manager::TEXTAREA,
				'rows'    => 4,
				'default' => '',
			)
		);

		$this->add_control(
			'slides',
			array(
				'label'       => 'Slides',
				'type'        => \Elementor\Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'title_field' => '{{{ kicker || title }}}',
				'default'     => array(
					array(
						'kicker' => 'Confianza',
						'title'  => 'Comparte tu bici con *confianza*',
						'text'   => 'Cada persona se verifica y las dos partes firman un contrato por el tiempo del alquiler.',
					),
					array(
						'kicker' => 'Actividades',
						'title'  => 'Crea actividades para los *demás*',
						'text'   => 'Publica tu ruta, tu picnic o tu taller, y la comunidad lo encuentra en el calendario.',
					),
					array(
						'kicker' => 'Perfil',
						'title'  => 'Cuida tu *perfil*',
						'text'   => 'Con buenas fotografías y descripciones atraes el interés de otros.',
					),
					array(
						'kicker' => 'Reputación',
						'title'  => 'Cura tu *reputación*',
						'text'   => 'Reúne reseñas satisfactorias de tus alquileres y actividades, y crece con cada aporte.',
					),
					array(
						'kicker' => 'Ayuda',
						'title'  => 'Encuentra *ayuda*',
						'text'   => 'Cuando algo falla, la comunidad responde en el grupo de Ayuda y entre propietarios y anfitriones.',
					),
					array(
						'kicker' => 'Edukleta',
						'title'  => 'Aprende y *enseña*',
						'text'   => 'Las clases y talleres de Edukleta te llevan de lo básico a manejar la ciudad con seguridad, con insignias que validan cada paso.',
					),
					array(
						'kicker' => 'Aliados',
						'title'  => 'Los aliados impulsan la *comunidad*',
						'text'   => 'Organizaciones, marcas y empresas patrocinan actividades.',
					),
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Content → Closing.
	 */
	private function register_closing_controls() {
		$this->start_controls_section( 'section_closing', array( 'label' => 'Closing' ) );

		$this->add_control(
			'closing_image',
			array(
				'label'   => 'Photo',
				'type'    => \Elementor\Controls_Manager::MEDIA,
				'default' => array( 'url' => CITYKLETA_URL . 'assets/images/story/closing.webp' ),
			)
		);

		$this->add_control(
			'closing_focus',
			array(
				'label'   => 'Photo Focus',
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => '50% 30%',
				'options' => array(
					'50% 0%'   => 'Top',
					'50% 30%'  => 'Upper',
					'50% 50%'  => 'Center',
					'50% 100%' => 'Bottom',
				),
			)
		);

		$this->add_control(
			'closing_kicker',
			array(
				'label'   => 'Kicker',
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => 'Citykleta',
			)
		);

		$this->add_control(
			'closing_title',
			array(
				'label'       => 'Title',
				'type'        => \Elementor\Controls_Manager::TEXTAREA,
				'rows'        => 2,
				'default'     => 'Es seguro compartir en *comunidad*',
				'description' => 'Wrap a word in *asterisks* to highlight it.',
			)
		);

		$this->add_control(
			'closing_button_text',
			array(
				'label'       => 'Button Text',
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => 'Pon tu bici en Citykleta',
				'description' => 'Leave empty for no button.',
			)
		);

		$this->add_control(
			'closing_button_link',
			array(
				'label'   => 'Button Link',
				'type'    => \Elementor\Controls_Manager::URL,
				'default' => array( 'url' => '' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Content → Settings.
	 */
	private function register_settings_controls() {
		$this->start_controls_section( 'section_settings', array( 'label' => 'Settings' ) );

		$this->add_control(
			'pace',
			array(
				'label'       => 'Scroll Per Scene',
				'type'        => \Elementor\Controls_Manager::SLIDER,
				'range'       => array( 'px' => array( 'min' => 0.5, 'max' => 2, 'step' => 0.1 ) ),
				'default'     => array( 'size' => 1 ),
				'description' => 'How much scrolling each scene takes, in card heights. More = slower.',
			)
		);

		$this->add_control(
			'show_numbers',
			array(
				'label'   => 'Number The Slides',
				'type'    => \Elementor\Controls_Manager::SWITCHER,
				'default' => 'yes',
			)
		);

		$this->add_control(
			'show_dots',
			array(
				'label'   => 'Scene Dots',
				'type'    => \Elementor\Controls_Manager::SWITCHER,
				'default' => 'yes',
			)
		);

		$this->add_control(
			'hint_text',
			array(
				'label'       => 'Scroll Hint',
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => 'Desliza para ver la historia ↓',
				'description' => 'Shown on the cover until you start scrolling. Leave empty for none.',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style → Card.
	 */
	private function register_card_style_controls() {
		$this->start_controls_section(
			'section_card_style',
			array(
				'label' => 'Card',
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'card_height',
			array(
				'label'          => 'Height',
				'type'           => \Elementor\Controls_Manager::SLIDER,
				'size_units'     => array( 'px', 'vh' ),
				'range'          => array(
					'px' => array( 'min' => 320, 'max' => 1200 ),
					'vh' => array( 'min' => 40, 'max' => 100 ),
				),
				'default'        => array( 'unit' => 'px', 'size' => 760 ),
				'tablet_default' => array( 'unit' => 'px', 'size' => 600 ),
				'mobile_default' => array( 'unit' => 'px', 'size' => 560 ),
				// A variable, not `height`: the no-JS layout (story.css) must be able to undo it.
				'selectors'      => array( '{{WRAPPER}} .citykleta-story' => '--citykleta-story-height: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_control(
			'card_bg',
			array(
				'label'     => 'Background Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#ffffff',
				'selectors' => array( '{{WRAPPER}} .citykleta-story, {{WRAPPER}} .citykleta-story__shot.is-cover' => 'background-color: {{VALUE}};' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'     => 'card_border',
				'selector' => '{{WRAPPER}} .citykleta-story',
			)
		);

		$this->add_responsive_control(
			'card_radius',
			array(
				'label'      => 'Border Radius',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 60 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 24 ),
				'selectors'  => array( '{{WRAPPER}} .citykleta-story' => 'border-radius: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style → Texts (cover and slides).
	 */
	private function register_text_style_controls() {
		$this->start_controls_section(
			'section_text_style',
			array(
				'label' => 'Texts',
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'kicker_color',
			array(
				'label'     => 'Kicker Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#e0960a',
				'selectors' => array( '{{WRAPPER}} .citykleta-story__copy .citykleta-story__kicker' => 'color: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'title_color',
			array(
				'label'     => 'Title Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#151b1d',
				'selectors' => array( '{{WRAPPER}} .citykleta-story__copy .citykleta-story__title' => 'color: {{VALUE}};' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'title_typography',
				'label'    => 'Title Typography',
				'selector' => '{{WRAPPER}} .citykleta-story__title',
			)
		);

		$this->add_control(
			'text_color',
			array(
				'label'     => 'Text Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#5d6466',
				'selectors' => array( '{{WRAPPER}} .citykleta-story__copy .citykleta-story__text' => 'color: {{VALUE}};' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'text_typography',
				'label'    => 'Text Typography',
				'selector' => '{{WRAPPER}} .citykleta-story__text',
			)
		);

		$this->add_control(
			'highlight_color',
			array(
				'label'     => 'Highlight Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#fed94c',
				'selectors' => array( '{{WRAPPER}} .citykleta-story' => '--citykleta-story-highlight: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'number_color',
			array(
				'label'     => 'Slide Number Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => 'rgba(21, 27, 29, 0.16)',
				'selectors' => array( '{{WRAPPER}} .citykleta-story__num' => '-webkit-text-stroke-color: {{VALUE}};' ),
				'condition' => array( 'show_numbers' => 'yes' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style → Closing (text over the photo, and the button).
	 */
	private function register_closing_style_controls() {
		$this->start_controls_section(
			'section_closing_style',
			array(
				'label' => 'Closing',
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'closing_text_color',
			array(
				'label'     => 'Title Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#ffffff',
				'selectors' => array( '{{WRAPPER}} .citykleta-story__final' => 'color: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'closing_kicker_color',
			array(
				'label'     => 'Kicker Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#fed94c',
				'selectors' => array( '{{WRAPPER}} .citykleta-story__final .citykleta-story__kicker' => 'color: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'closing_shade',
			array(
				'label'       => 'Shade Under The Text',
				'type'        => \Elementor\Controls_Manager::SLIDER,
				'range'       => array( 'px' => array( 'min' => 0, 'max' => 1, 'step' => 0.05 ) ),
				'default'     => array( 'size' => 0.8 ),
				'description' => 'How dark the bottom of the photo gets, so the text reads on any photo.',
				'selectors'   => array( '{{WRAPPER}} .citykleta-story' => '--citykleta-story-shade: {{SIZE}};' ),
			)
		);

		$this->add_control(
			'button_heading',
			array(
				'label'     => 'Button',
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'button_bg',
			array(
				'label'     => 'Background Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#fed94c',
				'selectors' => array( '{{WRAPPER}} .citykleta-story__cta' => 'background-color: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'button_color',
			array(
				'label'     => 'Text Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#151b1d',
				'selectors' => array( '{{WRAPPER}} .citykleta-story__cta' => 'color: {{VALUE}};' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'button_typography',
				'selector' => '{{WRAPPER}} .citykleta-story__cta',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Front-end output.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		$slides   = is_array( $settings['slides'] ) ? array_values( $settings['slides'] ) : array();
		$scenes   = count( $slides ) + 2; // cover + slides + closing
		$pace     = isset( $settings['pace']['size'] ) ? (float) $settings['pace']['size'] : 1;
		$left     = isset( $settings['person_left']['size'] ) ? (float) $settings['person_left']['size'] : 44;
		$right    = isset( $settings['person_right']['size'] ) ? (float) $settings['person_right']['size'] : 72;
		if ( $right <= $left ) {
			$right = min( 100, $left + 10 );
		}

		printf(
			'<div class="citykleta-story" data-person="%s" style="--citykleta-story-scenes: %d; --citykleta-story-pace: %s;">',
			esc_attr( ( $left / 100 ) . ',' . ( $right / 100 ) ),
			(int) $scenes,
			esc_attr( max( 0.5, min( 2, $pace ) ) )
		);
		echo '<div class="citykleta-story__scroller"><div class="citykleta-story__track"><div class="citykleta-story__stage">';

		// Photos: the cover (scene 0), one per slide from the second (the cover
		// plays the first slide's image), and the closing.
		echo '<div class="citykleta-story__window">';
		$this->render_shot( 0, 'is-cover', $settings['cover_image'], 'full', '', $settings['cover_title'] );
		foreach ( $slides as $n => $slide ) {
			if ( 0 === $n ) {
				continue;
			}
			$scene = $n + 1;
			$side  = 1 === $scene % 2 ? 'is-slot is-left' : 'is-slot';
			// Rows saved before a field existed may lack it.
			$this->render_shot( $scene, $side, $slide['image'] ?? array(), 'large', $slide['focus'] ?? '', $slide['title'] ?? '' );
		}
		$this->render_shot( $scenes - 1, 'is-full', $settings['closing_image'], 'full', $settings['closing_focus'], $settings['closing_title'] );
		echo '</div>';

		// Texts beside the photos: cover and slides.
		echo '<div class="citykleta-story__copy">';
		$this->render_step( 0, $settings['cover_kicker'], $settings['cover_title'], $settings['cover_text'] );
		foreach ( $slides as $n => $slide ) {
			$number = 'yes' === $settings['show_numbers'] ? sprintf( '%02d', $n + 1 ) : '';
			$this->render_step( $n + 1, $slide['kicker'] ?? '', $slide['title'] ?? '', $slide['text'] ?? '', $number );
		}
		echo '</div>';

		// The closing text, centred at the bottom over the full-card photo.
		echo '<div class="citykleta-story__final">';
		$button = '';
		if ( '' !== trim( (string) $settings['closing_button_text'] ) ) {
			$link   = $settings['closing_button_link'];
			$button = sprintf(
				'<a class="citykleta-story__cta" href="%s"%s>%s</a>',
				esc_url( empty( $link['url'] ) ? '#' : $link['url'] ),
				empty( $link['is_external'] ) ? '' : ' target="_blank" rel="noopener"',
				esc_html( $settings['closing_button_text'] )
			);
		}
		$this->render_step( $scenes - 1, $settings['closing_kicker'], $settings['closing_title'], '', '', $button );
		echo '</div>';

		if ( '' !== trim( (string) $settings['hint_text'] ) ) {
			printf( '<div class="citykleta-story__hint" aria-hidden="true">%s</div>', esc_html( $settings['hint_text'] ) );
		}
		if ( 'yes' === $settings['show_dots'] ) {
			echo '<div class="citykleta-story__dots"></div>';
		}

		echo '</div></div></div></div>';
	}

	/**
	 * One scene's photo, or a placeholder when it has none yet.
	 *
	 * @param int    $scene Scene index.
	 * @param string $class Extra classes (is-cover, is-slot, is-left, is-full).
	 * @param array  $media MEDIA control value.
	 * @param string $size  Attachment size to load.
	 * @param string $focus object-position for the photo ('' = default).
	 * @param string $title The scene's title, used as alt text.
	 */
	private function render_shot( $scene, $class, $media, $size, $focus, $title ) {
		$url = $this->image_url( is_array( $media ) ? $media : array(), $size );
		printf( '<figure class="citykleta-story__shot %s" data-scene="%d">', esc_attr( $class ), (int) $scene );
		if ( '' !== $url ) {
			printf(
				'<img src="%s" alt="%s"%s%s>',
				esc_url( $url ),
				esc_attr( str_replace( '*', '', (string) $title ) ),
				$scene > 1 ? ' loading="lazy"' : '',
				'' !== $focus ? ' style="object-position: ' . esc_attr( $focus ) . ';"' : ''
			);
		} else {
			if ( 0 === $scene ) {
				$label = 'Cover photo';
			} elseif ( false !== strpos( $class, 'is-full' ) ) {
				$label = 'Closing photo';
			} else {
				$label = sprintf( 'Slide %d photo', $scene ); // scene n = slide n
			}
			printf( '<div class="citykleta-story__placeholder">%s</div>', esc_html( $label ) );
		}
		echo '</figure>';
	}

	/**
	 * One scene's text.
	 *
	 * @param int    $scene  Scene index.
	 * @param string $kicker Small line above the title.
	 * @param string $title  Title (*word* is highlighted).
	 * @param string $text   Paragraph.
	 * @param string $number Big slide number behind the text ('' for none).
	 * @param string $extra  Already-escaped HTML appended at the end (the button).
	 */
	private function render_step( $scene, $kicker, $title, $text, $number = '', $extra = '' ) {
		printf( '<article class="citykleta-story__step" data-scene="%d">', (int) $scene );
		if ( '' !== $number ) {
			printf( '<span class="citykleta-story__num" aria-hidden="true">%s</span>', esc_html( $number ) );
		}
		if ( '' !== trim( (string) $kicker ) ) {
			printf( '<div class="citykleta-story__kicker">%s</div>', esc_html( $kicker ) );
		}
		if ( '' !== trim( (string) $title ) ) {
			printf( '<h3 class="citykleta-story__title">%s</h3>', $this->highlight( $title ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in highlight().
		}
		if ( '' !== trim( (string) $text ) ) {
			printf( '<p class="citykleta-story__text">%s</p>', esc_html( $text ) );
		}
		echo $extra; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped by the caller.
		echo '</article>';
	}

	/**
	 * Escapes a title and turns *word* into the highlighted <mark>word</mark>.
	 *
	 * @param string $title Raw title.
	 * @return string Safe HTML.
	 */
	private function highlight( $title ) {
		return preg_replace( '/\*([^*]+)\*/', '<mark>$1</mark>', esc_html( $title ) );
	}

	/**
	 * URL of a MEDIA control's image: the attachment at $size when it is one,
	 * else the URL stored in the control (e.g. the plugin's default photos).
	 *
	 * @param array  $media MEDIA control value.
	 * @param string $size  Attachment size.
	 * @return string URL, or '' when there is no image.
	 */
	private function image_url( $media, $size ) {
		if ( ! empty( $media['id'] ) ) {
			$url = wp_get_attachment_image_url( (int) $media['id'], $size );
			if ( $url ) {
				return $url;
			}
		}
		return empty( $media['url'] ) ? '' : (string) $media['url'];
	}
}
