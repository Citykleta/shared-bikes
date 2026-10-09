<?php
/**
 * "Group Chat" widget: a mock group conversation for the community section.
 *
 * Purely decorative — no data source, no backend. The messages are typed in a
 * repeater and, optionally, play back one by one with a "typing…" indicator,
 * like a real group chat (people agreeing on a weekend ride, a meeting point, a
 * community clean-up…).
 *
 * Deliberately a generic chat, not a WhatsApp replica: no WhatsApp name, logo or
 * colors. A page that looks like a real screenshot of that app would read as a
 * genuine testimonial, which this isn't.
 *
 * Progressive enhancement: the server prints every message visible. Only
 * citykleta-chat.js hides them and plays them back, so with no JS (or with
 * prefers-reduced-motion) the whole conversation is simply there.
 *
 * Kept deliberately small: no header, no message times, one text color and one
 * typography for every text in it. The message bar and the conversation share
 * the chat's background.
 *
 * No shortcode on purpose: the content is a list of messages, which does not
 * fit in shortcode attributes.
 *
 * @package Citykleta_Shared_Bikes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Citykleta_Chat_Widget.
 */
class Citykleta_Chat_Widget extends \Elementor\Widget_Base {

	/**
	 * Message types of a repeater row.
	 */
	const TYPE_OTHER  = 'message';
	const TYPE_ME     = 'me';
	const TYPE_SYSTEM = 'system';

	/**
	 * Name colors for the group members, picked per author by a hash of the
	 * name — the same person always gets the same color, like in a real group.
	 */
	const AUTHOR_COLORS = array( '#1f7aec', '#c2338a', '#0f8a6a', '#c26a00', '#7c3aed', '#d03a2f', '#0b7f99', '#5b8a12' );

	/**
	 * Internal widget name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'citykleta_chat';
	}

	/**
	 * Title shown in the Elementor editor.
	 *
	 * @return string
	 */
	public function get_title() {
		return 'Group Chat';
	}

	/**
	 * Widget icon in the Elementor panel.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-comments';
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
		return array( 'chat', 'group', 'grupo', 'conversation', 'conversacion', 'messages', 'mensajes', 'community', 'comunidad', 'citykleta' );
	}

	/**
	 * Controls shown in Elementor's left panel.
	 */
	protected function register_controls() {
		$this->register_messages_controls();
		$this->register_animation_controls();
		$this->register_box_style_controls();
		$this->register_chat_style_controls();
		$this->register_bubble_style_controls();
		$this->register_footer_style_controls();
	}

	/**
	 * Content → Messages: the conversation itself, plus what each bubble shows.
	 */
	private function register_messages_controls() {
		$this->start_controls_section( 'section_messages', array( 'label' => 'Messages' ) );

		$repeater = new \Elementor\Repeater();

		$repeater->add_control(
			'msg_type',
			array(
				'label'   => 'Type',
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => self::TYPE_OTHER,
				'options' => array(
					self::TYPE_OTHER  => 'Message by Other',
					self::TYPE_ME     => 'Message by Me',
					self::TYPE_SYSTEM => 'Notice (centered)',
				),
			)
		);

		$repeater->add_control(
			'author',
			array(
				'label'     => 'Author',
				'type'      => \Elementor\Controls_Manager::TEXT,
				'default'   => 'Laura',
				'condition' => array( 'msg_type' => self::TYPE_OTHER ),
			)
		);

		$repeater->add_control(
			'avatar',
			array(
				'label'       => 'Author Picture',
				'type'        => \Elementor\Controls_Manager::MEDIA,
				'default'     => array( 'url' => '' ),
				'description' => 'Empty = initials. Only the first message of each run shows it.',
				'condition'   => array( 'msg_type' => self::TYPE_OTHER ),
			)
		);

		$repeater->add_control(
			'text',
			array(
				'label'   => 'Text',
				'type'    => \Elementor\Controls_Manager::TEXTAREA,
				'rows'    => 3,
				'default' => '',
			)
		);

		$this->add_control(
			'messages',
			array(
				'label'       => 'Conversation',
				'type'        => \Elementor\Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'default'     => self::default_messages(),
				'title_field' => '{{{ "system" === msg_type ? "— " + text : ( "me" === msg_type ? "➜ " : author + ": " ) + text }}}',
			)
		);

		$this->add_control(
			'show_avatars',
			array(
				'label'     => 'Show Author Pictures',
				'type'      => \Elementor\Controls_Manager::SWITCHER,
				'default'   => 'yes',
				'separator' => 'before',
			)
		);

		$this->add_control(
			'show_ticks',
			array(
				'label'   => 'Show Read Ticks',
				'type'    => \Elementor\Controls_Manager::SWITCHER,
				'default' => 'yes',
			)
		);

		$this->add_control(
			'show_footer',
			array(
				'label'   => 'Show Message Bar',
				'type'    => \Elementor\Controls_Manager::SWITCHER,
				'default' => 'yes',
			)
		);

		$this->add_control(
			'footer_placeholder',
			array(
				'label'     => 'Message Bar Text',
				'type'      => \Elementor\Controls_Manager::TEXT,
				'default'   => 'Escribe un mensaje…',
				'condition' => array( 'show_footer' => 'yes' ),
			)
		);

		$this->add_control(
			'show_attach',
			array(
				'label'     => 'Show Left Button',
				'type'      => \Elementor\Controls_Manager::SWITCHER,
				'default'   => 'yes',
				'condition' => array( 'show_footer' => 'yes' ),
			)
		);

		$this->add_control(
			'attach_icon',
			array(
				'label'     => 'Left Button Icon',
				'type'      => \Elementor\Controls_Manager::ICONS,
				'default'   => array(
					'value'   => 'fas fa-plus',
					'library' => 'fa-solid',
				),
				'condition' => array(
					'show_footer' => 'yes',
					'show_attach' => 'yes',
				),
			)
		);

		$this->add_control(
			'send_icon',
			array(
				'label'       => 'Send Button Icon',
				'type'        => \Elementor\Controls_Manager::ICONS,
				'default'     => array(
					'value'   => 'fas fa-arrow-up',
					'library' => 'fa-solid',
				),
				'description' => 'Any icon from the library, or upload an SVG. Empty = the default arrow.',
				'condition'   => array( 'show_footer' => 'yes' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Content → Animation: when and how fast the messages play back.
	 */
	private function register_animation_controls() {
		$this->start_controls_section( 'section_animation', array( 'label' => 'Animation' ) );

		$this->add_control(
			'trigger',
			array(
				'label'       => 'Play',
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => 'viewport',
				'options'     => array(
					'viewport' => 'When scrolled into view',
					'hover'    => 'While the mouse is over it',
					'none'     => 'No animation (show everything)',
				),
				'description' => 'Touch screens have no mouse, so "mouse" falls back to "scrolled into view" there. Visitors whose system asks for reduced motion always see the whole chat.',
			)
		);

		$this->add_control(
			'speed',
			array(
				'label'     => 'Speed',
				'type'      => \Elementor\Controls_Manager::SELECT,
				'default'   => '1',
				'options'   => array(
					'0.6' => 'Slow',
					'1'   => 'Normal',
					'1.6' => 'Fast',
				),
				'condition' => array( 'trigger!' => 'none' ),
			)
		);

		$this->add_control(
			'pause',
			array(
				'label'       => 'Pause Between Messages (ms)',
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'default'     => 900,
				'min'         => 0,
				'max'         => 10000,
				'step'        => 100,
				'description' => 'Typing time is added on top, longer for longer messages.',
				'condition'   => array( 'trigger!' => 'none' ),
			)
		);

		$this->add_control(
			'loop',
			array(
				'label'     => 'Loop',
				'type'      => \Elementor\Controls_Manager::SWITCHER,
				'condition' => array( 'trigger!' => 'none' ),
			)
		);

		$this->add_control(
			'loop_delay',
			array(
				'label'     => 'Restart After (s)',
				'type'      => \Elementor\Controls_Manager::NUMBER,
				'default'   => 8,
				'min'       => 1,
				'max'       => 120,
				'condition' => array(
					'trigger!' => 'none',
					'loop'     => 'yes',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style → Box: size and frame of the whole chat.
	 */
	private function register_box_style_controls() {
		$this->start_controls_section(
			'section_style_box',
			array(
				'label' => 'Box',
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'max_width',
			array(
				'label'      => 'Max Width',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', '%' ),
				'range'      => array(
					'px' => array(
						'min' => 260,
						'max' => 900,
					),
				),
				'default'    => array(
					'size' => 420,
					'unit' => 'px',
				),
				'selectors'  => array( '{{WRAPPER}} .citykleta-chat' => 'max-width: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'align',
			array(
				'label'     => 'Alignment',
				'type'      => \Elementor\Controls_Manager::CHOOSE,
				'options'   => array(
					'0 auto 0 0' => array(
						'title' => 'Left',
						'icon'  => 'eicon-h-align-left',
					),
					'0 auto'     => array(
						'title' => 'Center',
						'icon'  => 'eicon-h-align-center',
					),
					'0 0 0 auto' => array(
						'title' => 'Right',
						'icon'  => 'eicon-h-align-right',
					),
				),
				'default'   => '0 auto',
				'selectors' => array( '{{WRAPPER}} .citykleta-chat' => 'margin: {{VALUE}};' ),
			)
		);

		$this->add_responsive_control(
			'body_height',
			array(
				'label'       => 'Height',
				'type'        => \Elementor\Controls_Manager::SLIDER,
				'size_units'  => array( 'px', 'vh' ),
				'range'       => array(
					'px' => array(
						'min' => 200,
						'max' => 1000,
					),
				),
				'default'     => array(
					'size' => 560,
					'unit' => 'px',
				),
				'description' => 'Height of the whole chat, message bar included — hiding the bar gives the conversation more room, not a shorter box. Fixed, so the page doesn\'t jump while messages appear; longer conversations scroll inside.',
				// Control ID kept as body_height: it is already saved in placed widgets.
				'selectors'   => array( '{{WRAPPER}} .citykleta-chat' => 'height: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'     => 'box_border',
				'selector' => '{{WRAPPER}} .citykleta-chat',
			)
		);

		$this->add_responsive_control(
			'box_radius',
			array(
				'label'      => 'Border Radius',
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'default'    => array(
					'top'      => 18,
					'right'    => 18,
					'bottom'   => 18,
					'left'     => 18,
					'unit'     => 'px',
					'isLinked' => true,
				),
				'selectors'  => array( '{{WRAPPER}} .citykleta-chat' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'           => 'box_shadow',
				'selector'       => '{{WRAPPER}} .citykleta-chat',
				'fields_options' => array(
					'box_shadow_type' => array( 'default' => 'yes' ),
					'box_shadow'      => array(
						'default' => array(
							'horizontal' => 0,
							'vertical'   => 12,
							'blur'       => 32,
							'spread'     => -8,
							'color'      => 'rgba(21, 27, 29, 0.25)',
						),
					),
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style → Chat: the background shared by conversation and message bar, and
	 * the one text color and typography every text in the chat uses.
	 */
	private function register_chat_style_controls() {
		$this->start_controls_section(
			'section_style_body',
			array(
				'label' => 'Chat',
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'body_bg',
			array(
				'label'       => 'Background Color',
				'type'        => \Elementor\Controls_Manager::COLOR,
				'default'     => '#f1ece1',
				'description' => 'Also the message bar\'s background.',
				'selectors'   => array( '{{WRAPPER}} .citykleta-chat' => 'background-color: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'body_pattern',
			array(
				'label'        => 'Dotted Pattern',
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'prefix_class' => 'citykleta-chat-pattern-',
			)
		);

		$this->add_control(
			'text_color',
			array(
				'label'       => 'Text Color',
				'type'        => \Elementor\Controls_Manager::COLOR,
				'default'     => '#151b1d',
				'description' => 'Every text: messages, notices and the message bar. Author names keep one color per person.',
				'selectors'   => array( '{{WRAPPER}} .citykleta-chat' => 'color: {{VALUE}};' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'chat_typography',
				'selector' => '{{WRAPPER}} .citykleta-chat',
			)
		);

		$this->add_responsive_control(
			'body_padding',
			array(
				'label'      => 'Conversation Padding',
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'default'    => array(
					'top'      => 12,
					'right'    => 12,
					'bottom'   => 12,
					'left'     => 12,
					'unit'     => 'px',
					'isLinked' => true,
				),
				'selectors'  => array( '{{WRAPPER}} .citykleta-chat__body' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'sender_gap',
			array(
				'label'       => 'Space Between People',
				'type'        => \Elementor\Controls_Manager::SLIDER,
				'size_units'  => array( 'px' ),
				'range'       => array(
					'px' => array(
						'min' => 0,
						'max' => 60,
					),
				),
				'default'     => array(
					'size' => 8,
					'unit' => 'px',
				),
				'description' => 'Gap before the first message of each person (and before a notice). Messages in a row from the same person stay together.',
				// A variable, not margin-top directly: chat.css falls back to the same 8px
				// when this control has no generated CSS yet (a widget placed before it existed).
				'selectors'   => array( '{{WRAPPER}} .citykleta-chat' => '--citykleta-chat-sender-gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_control(
			'system_bg',
			array(
				'label'     => 'Notice Background',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#fff4c2',
				'separator' => 'before',
				'selectors' => array( '{{WRAPPER}} .citykleta-chat__system' => 'background-color: {{VALUE}};' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style → Bubbles.
	 */
	private function register_bubble_style_controls() {
		$this->start_controls_section(
			'section_style_bubbles',
			array(
				'label' => 'Bubbles',
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'bubble_in_bg',
			array(
				'label'     => 'Others\' Background',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#ffffff',
				'selectors' => array( '{{WRAPPER}} .citykleta-chat__row.is-in .citykleta-chat__bubble' => 'background-color: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'bubble_out_bg',
			array(
				'label'     => '"Me" Background',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#ffe680',
				'selectors' => array( '{{WRAPPER}} .citykleta-chat__row.is-out .citykleta-chat__bubble' => 'background-color: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'ticks_color',
			array(
				'label'     => 'Read Ticks Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#1f7aec',
				'selectors' => array( '{{WRAPPER}} .citykleta-chat__ticks' => 'color: {{VALUE}};' ),
				'condition' => array( 'show_ticks' => 'yes' ),
			)
		);

		$this->add_control(
			'typing_dots_color',
			array(
				'label'     => 'Typing Dots Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#8a8476',
				'selectors' => array( '{{WRAPPER}} .citykleta-chat__dots span' => 'background-color: {{VALUE}};' ),
				'condition' => array( 'trigger!' => 'none' ),
			)
		);

		$this->add_responsive_control(
			'bubble_radius',
			array(
				'label'      => 'Border Radius',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 24,
					),
				),
				'default'    => array(
					'size' => 10,
					'unit' => 'px',
				),
				'selectors'  => array( '{{WRAPPER}} .citykleta-chat__bubble' => '--citykleta-chat-radius: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Style → Message bar. Its background is the chat's own (Style → Chat).
	 */
	private function register_footer_style_controls() {
		$this->start_controls_section(
			'section_style_footer',
			array(
				'label'     => 'Message Bar',
				'tab'       => \Elementor\Controls_Manager::TAB_STYLE,
				'condition' => array( 'show_footer' => 'yes' ),
			)
		);

		$this->add_responsive_control(
			'footer_padding',
			array(
				'label'      => 'Padding',
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'default'    => array(
					'top'      => 12,
					'right'    => 12,
					'bottom'   => 12,
					'left'     => 12,
					'unit'     => 'px',
					'isLinked' => true,
				),
				'selectors'  => array( '{{WRAPPER}} .citykleta-chat__footer' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->add_control(
			'composer_heading',
			array(
				'label'     => 'Field',
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_responsive_control(
			'composer_border_width',
			array(
				'label'      => 'Border Width',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 8,
					),
				),
				'default'    => array(
					'size' => 1,
					'unit' => 'px',
				),
				'selectors'  => array( '{{WRAPPER}} .citykleta-chat__composer' => 'border-width: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_control(
			'composer_border_color',
			array(
				'label'     => 'Border Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#d9d3c6',
				'selectors' => array( '{{WRAPPER}} .citykleta-chat__composer' => 'border-color: {{VALUE}};' ),
			)
		);

		$this->add_responsive_control(
			'composer_radius',
			array(
				'label'      => 'Border Radius',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 48,
					),
				),
				'default'    => array(
					'size' => 24,
					'unit' => 'px',
				),
				'selectors'  => array( '{{WRAPPER}} .citykleta-chat__composer' => 'border-radius: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'composer_height',
			array(
				'label'      => 'Min Height',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 40,
						'max' => 300,
					),
				),
				'default'    => array(
					'size' => 140,
					'unit' => 'px',
				),
				'selectors'  => array( '{{WRAPPER}} .citykleta-chat__composer' => 'min-height: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_control(
			'attach_heading',
			array(
				'label'     => 'Left Button',
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
				'condition' => array( 'show_attach' => 'yes' ),
			)
		);

		$this->add_control(
			'attach_color',
			array(
				'label'     => 'Icon Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#5c5546',
				'selectors' => array( '{{WRAPPER}} .citykleta-chat__attach' => 'color: {{VALUE}};' ),
				'condition' => array( 'show_attach' => 'yes' ),
			)
		);

		$this->add_responsive_control(
			'attach_size',
			array(
				'label'      => 'Icon Size',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 10,
						'max' => 48,
					),
				),
				'default'    => array(
					'size' => 22,
					'unit' => 'px',
				),
				'selectors'  => array( '{{WRAPPER}} .citykleta-chat__attach' => 'font-size: {{SIZE}}{{UNIT}};' ),
				'condition'  => array( 'show_attach' => 'yes' ),
			)
		);

		$this->add_control(
			'send_heading',
			array(
				'label'     => 'Send Button',
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'send_bg',
			array(
				'label'     => 'Background Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#fed94c',
				'selectors' => array( '{{WRAPPER}} .citykleta-chat__send' => 'background-color: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'send_color',
			array(
				'label'     => 'Icon Color',
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#151b1d',
				'selectors' => array( '{{WRAPPER}} .citykleta-chat__send' => 'color: {{VALUE}};' ),
			)
		);

		$this->add_responsive_control(
			'send_size',
			array(
				'label'      => 'Button Size',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 24,
						'max' => 80,
					),
				),
				'default'    => array(
					'size' => 42,
					'unit' => 'px',
				),
				'selectors'  => array( '{{WRAPPER}} .citykleta-chat__send' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'send_icon_size',
			array(
				'label'      => 'Icon Size',
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 8,
						'max' => 40,
					),
				),
				'default'    => array(
					'size' => 18,
					'unit' => 'px',
				),
				'selectors'  => array( '{{WRAPPER}} .citykleta-chat__send' => 'font-size: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * The conversation a freshly dropped widget starts with: a group agreeing on
	 * a Saturday ride and a beach clean-up. Front-end text, so Spanish.
	 *
	 * @return array
	 */
	private static function default_messages() {
		$rows = array(
			array( self::TYPE_SYSTEM, '', 'Laura creó el grupo «Pedaleando La Habana»' ),
			array( self::TYPE_OTHER, 'Laura', '¡Buenas! ¿Quién se apunta el sábado a rodar por el Malecón? 🚲' ),
			array( self::TYPE_OTHER, 'Gustavo', 'Yo voy. Salgamos temprano, que a las 10 ya el sol pica' ),
			array( self::TYPE_OTHER, 'María', '¡Cuenten conmigo! Ya pedí una bici en Citykleta para ese día' ),
			array( self::TYPE_ME, '', 'Me apunto también. ¿Dónde nos vemos?' ),
			array( self::TYPE_OTHER, 'Laura', 'En el parque de La Punta, 8:00 am' ),
			array( self::TYPE_OTHER, 'Alfonso', 'Y de regreso podemos ayudar con la limpieza de la playa en Santa María 🌊' ),
			array( self::TYPE_OTHER, 'Laura', '¡Buenísima idea! Yo llevo guantes y bolsas' ),
			array( self::TYPE_ME, '', 'Perfecto, ¡nos vemos el sábado! 👋' ),
		);

		$messages = array();
		foreach ( $rows as $row ) {
			$messages[] = array(
				'msg_type' => $row[0],
				'author'   => $row[1],
				'text'     => $row[2],
			);
		}

		return $messages;
	}

	/**
	 * Up to two initials of a name, for the avatar fallback.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	private static function initials( $name ) {
		$initials = '';
		foreach ( array_slice( preg_split( '/\s+/u', trim( $name ), -1, PREG_SPLIT_NO_EMPTY ), 0, 2 ) as $word ) {
			$initials .= mb_substr( $word, 0, 1 );
		}

		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $initials ) : strtoupper( $initials );
	}

	/**
	 * The same color for the same author, every time.
	 *
	 * @param string $name Author name.
	 * @return string
	 */
	private static function author_color( $name ) {
		return self::AUTHOR_COLORS[ crc32( $name ) % count( self::AUTHOR_COLORS ) ];
	}

	/**
	 * Draws the widget.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		$messages = is_array( $settings['messages'] ) ? $settings['messages'] : array();
		$trigger  = in_array( $settings['trigger'], array( 'viewport', 'hover', 'none' ), true ) ? $settings['trigger'] : 'viewport';
		$animated = 'none' !== $trigger;
		$avatars  = 'yes' === $settings['show_avatars'];
		$ticks    = 'yes' === $settings['show_ticks'];

		$attrs = array(
			'class'        => 'citykleta-chat' . ( $avatars ? ' has-avatars' : '' ),
			'data-trigger' => $trigger,
		);
		if ( $animated ) {
			$attrs['data-speed']      = (string) (float) $settings['speed'];
			$attrs['data-pause']      = (string) absint( $settings['pause'] );
			$attrs['data-loop']       = 'yes' === $settings['loop'] ? 'yes' : 'no';
			$attrs['data-loop-delay'] = (string) max( 1, absint( $settings['loop_delay'] ) );
		}

		echo '<div';
		foreach ( $attrs as $name => $value ) {
			printf( ' %s="%s"', esc_attr( $name ), esc_attr( $value ) );
		}
		echo '>';

		// __body is the fixed window (border, radius, padding); __scroll is what
		// scrolls inside it. Padding on the scroller itself would scroll away
		// with the messages instead of staying as a margin of the window.
		echo '<div class="citykleta-chat__body"><div class="citykleta-chat__scroll">';

		$previous = null;
		foreach ( $messages as $message ) {
			$text = isset( $message['text'] ) ? trim( (string) $message['text'] ) : '';
			if ( '' === $text ) {
				continue;
			}

			$type = isset( $message['msg_type'] ) ? $message['msg_type'] : self::TYPE_OTHER;

			if ( self::TYPE_SYSTEM === $type ) {
				printf( '<div class="citykleta-chat__item citykleta-chat__system">%s</div>', esc_html( $text ) );
				$previous = null;
				continue;
			}

			$mine   = self::TYPE_ME === $type;
			$author = $mine ? '' : trim( (string) $message['author'] );
			$key    = $mine ? "\0me" : $author;
			$first  = $key !== $previous;

			$previous = $key;

			printf(
				'<div class="citykleta-chat__item citykleta-chat__row %1$s%2$s" style="--citykleta-chat-author: %3$s;"%4$s>',
				$mine ? 'is-out' : 'is-in',
				$first ? ' is-first' : '',
				esc_attr( self::author_color( $author ) ),
				$mine ? '' : ' data-author="' . esc_attr( $author ) . '"'
			);

			if ( ! $mine && $avatars ) {
				$this->render_avatar( $first ? $author : '', $first && ! empty( $message['avatar']['url'] ) ? $message['avatar']['url'] : '' );
			}

			echo '<div class="citykleta-chat__bubble">';
			if ( ! $mine && $first && '' !== $author ) {
				printf( '<span class="citykleta-chat__author">%s</span>', esc_html( $author ) );
			}
			printf( '<span class="citykleta-chat__text">%s</span>', esc_html( $text ) );

			if ( $mine && $ticks ) {
				echo '<span class="citykleta-chat__meta"><svg class="citykleta-chat__ticks" viewBox="0 0 16 11" width="16" height="11" aria-hidden="true" focusable="false"><path fill="currentColor" d="M11.07.65a.5.5 0 0 1 .08.7L5.4 8.6a.5.5 0 0 1-.74.04L1.65 5.6a.5.5 0 1 1 .7-.7l2.62 2.6L10.37.73a.5.5 0 0 1 .7-.08zm3.5 0a.5.5 0 0 1 .08.7L8.9 8.6a.5.5 0 0 1-.74.04l-.6-.6a.5.5 0 1 1 .7-.71l.21.2L13.87.73a.5.5 0 0 1 .7-.08z"/></svg></span>';
			}

			echo '</div></div>';
		}

		if ( $animated ) {
			// The "typing…" bubble. citykleta-chat.js moves it in front of the
			// next message and fills in the author; hidden until then.
			echo '<div class="citykleta-chat__row citykleta-chat__typing is-in is-first" hidden>';
			if ( $avatars ) {
				$this->render_avatar( '', '' );
			}
			echo '<div class="citykleta-chat__bubble"><span class="citykleta-chat__author"></span><span class="citykleta-chat__dots" aria-hidden="true"><span></span><span></span><span></span></span></div></div>';
		}

		echo '</div></div>';

		if ( 'yes' === $settings['show_footer'] ) {
			$this->render_footer( $settings );
		}

		echo '</div>';
	}

	/**
	 * The message bar: a tall rounded field with the placeholder on top and a
	 * row of buttons under it (left button + send). Decorative — nothing in it
	 * can be typed or pressed, so it is hidden from assistive technology.
	 *
	 * @param array $settings Widget settings.
	 */
	private function render_footer( $settings ) {
		echo '<div class="citykleta-chat__footer" aria-hidden="true"><div class="citykleta-chat__composer">';
		printf( '<span class="citykleta-chat__input">%s</span>', esc_html( $settings['footer_placeholder'] ) );
		echo '<div class="citykleta-chat__actions">';

		if ( 'yes' === $settings['show_attach'] && ! empty( $settings['attach_icon']['value'] ) ) {
			echo '<span class="citykleta-chat__attach">';
			\Elementor\Icons_Manager::render_icon( $settings['attach_icon'], array( 'aria-hidden' => 'true' ) );
			echo '</span>';
		}

		echo '<span class="citykleta-chat__send">';
		if ( ! empty( $settings['send_icon']['value'] ) ) {
			\Elementor\Icons_Manager::render_icon( $settings['send_icon'], array( 'aria-hidden' => 'true' ) );
		} else {
			echo '<svg viewBox="0 0 24 24" focusable="false"><path d="M12 4l-7 7 1.4 1.4L11 7.8V20h2V7.8l4.6 4.6L19 11z"/></svg>';
		}
		echo '</span></div></div></div>';
	}

	/**
	 * One avatar slot. An empty name keeps the column (so a run of messages
	 * from the same person stays aligned) without drawing anything.
	 *
	 * @param string $name  Author name, or '' for an empty slot.
	 * @param string $image Picture URL, or '' for initials.
	 */
	private function render_avatar( $name, $image ) {
		if ( '' === $name ) {
			echo '<span class="citykleta-chat__avatar is-empty" aria-hidden="true"></span>';
			return;
		}

		if ( '' !== $image ) {
			printf( '<img class="citykleta-chat__avatar" src="%s" alt="" loading="lazy">', esc_url( $image ) );
			return;
		}

		printf( '<span class="citykleta-chat__avatar" aria-hidden="true">%s</span>', esc_html( self::initials( $name ) ) );
	}
}
