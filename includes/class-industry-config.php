<?php
/**
 * Industry Configuration
 *
 * Defines the industry → subtopic → per-provider prompt matrix.
 * Add new industries or subtopics by filtering 'twtaeo_industry_config'.
 *
 * @package TWTAEO_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TWTAEO_Industry_Config {

	/**
	 * Return the full industry/subtopic/prompt matrix.
	 * Filterable so third-party code can add industries without editing this file.
	 *
	 * @return array
	 */
	public static function get() {
		$config = array(

			// ── Home Services ────────────────────────────────────────────────
			'home-services' => array(
				'label'     => 'Home Services',
				'subtopics' => array(

					'service-page' => array(
						'label'  => 'Service Page',
						'panels' => array(
							'claude' => array(
								'label'  => 'Service Description & Benefits',
								'prompt' => 'Write a comprehensive, persuasive service page for "{title}". Cover: what the service is, why homeowners need it, what the process looks like, common problems it solves, and a clear call to action. Use a friendly, trustworthy tone. Aim for 400–600 words.',
							),
							'openai' => array(
								'label'  => 'Quick Summary',
								'prompt' => 'Write a concise 2–3 sentence intro for a service page about "{title}". Make it punchy and benefit-focused. Under 80 words.',
							),
							'perplexity' => array(
								'label'  => 'Research & Local Stats',
								'prompt' => 'Research "{title}" as a home service. Include: common causes that lead to this service, average costs, how often it is needed, relevant industry statistics, and what homeowners should look for in a provider. Cite sources where possible.',
							),
						),
					),

					'case-study' => array(
						'label'  => 'Case Study',
						'panels' => array(
							'claude' => array(
								'label'  => 'Customer Story',
								'prompt' => 'Write a case study narrative for a home services company that completed "{title}". Structure it as: the customer\'s problem, what was found, the solution provided, and the outcome. Use a real-feeling, story-driven tone. 300–500 words.',
							),
							'openai' => array(
								'label'  => 'Key Takeaway',
								'prompt' => 'Write a 2–3 sentence key takeaway or headline result for a case study about "{title}". Focus on the measurable outcome for the customer. Under 60 words.',
							),
							'perplexity' => array(
								'label'  => 'Technical Background',
								'prompt' => 'Provide technical background on "{title}" as a home services problem. What causes it, what happens if left unaddressed, and what does a professional repair or service involve? Include any relevant standards or failure statistics.',
							),
						),
					),

					'faq-page' => array(
						'label'  => 'FAQ Page',
						'panels' => array(
							'claude' => array(
								'label'  => 'Full FAQ Content',
								'prompt' => 'Write 6 frequently asked questions and detailed answers for a home services page about "{title}". Cover cost, process, timeline, signs of need, DIY vs professional, and warranty. Write in a helpful, plain-English tone.',
							),
							'openai' => array(
								'label'  => 'Question List',
								'prompt' => 'List 8 concise FAQ questions a homeowner would ask about "{title}". Questions only — no answers. One per line.',
							),
							'perplexity' => array(
								'label'  => 'Research-Backed Answers',
								'prompt' => 'Provide data-backed answers to common questions about "{title}" as a home service. Include statistics, industry standards, average costs, and expert consensus. Cite sources.',
							),
						),
					),

				),
			),

			// ── Retail ───────────────────────────────────────────────────────
			'retail' => array(
				'label'     => 'Retail',
				'subtopics' => array(

					'product-page' => array(
						'label'  => 'Product Page',
						'panels' => array(
							'claude' => array(
								'label'  => 'Product Description',
								'prompt' => 'Write a compelling product description for "{title}". Cover key features, benefits over alternatives, ideal use cases, and what makes it worth buying. Write in an engaging, conversion-focused tone. 300–400 words.',
							),
							'openai' => array(
								'label'  => 'Short Pitch',
								'prompt' => 'Write a punchy 1–2 sentence product pitch for "{title}". Benefit-first, conversion-focused. Under 60 words.',
							),
							'perplexity' => array(
								'label'  => 'Specs & Market Context',
								'prompt' => 'Research "{title}" as a retail product. Provide: typical technical specifications, how it compares to top competitors, price range, target market, and any notable reviews or industry recognition. Cite sources.',
							),
						),
					),

					'category-page' => array(
						'label'  => 'Category Page',
						'panels' => array(
							'claude' => array(
								'label'  => 'Category Overview',
								'prompt' => 'Write a category page overview for "{title}". Explain what this category covers, who it is for, what to look for when choosing, and why customers should shop here. Informative and SEO-friendly. 250–350 words.',
							),
							'openai' => array(
								'label'  => 'Category Intro',
								'prompt' => 'Write a 1–2 sentence header intro for a "{title}" category page. Clear, welcoming, benefit-led. Under 50 words.',
							),
							'perplexity' => array(
								'label'  => 'Market Trends',
								'prompt' => 'Research the "{title}" product category. Include: market size, growth trends, top brands, key buying criteria, and what consumers care most about in this category. Cite sources.',
							),
						),
					),

					'brand-story' => array(
						'label'  => 'Brand Story',
						'panels' => array(
							'claude' => array(
								'label'  => 'Brand Narrative',
								'prompt' => 'Write an engaging brand story for "{title}". Cover origin, mission, values, what makes this brand different, and why customers should care. Warm, authentic, and brand-building. 400–600 words.',
							),
							'openai' => array(
								'label'  => 'Tagline & Elevator Pitch',
								'prompt' => 'Write a memorable tagline and a one-paragraph elevator pitch for the brand "{title}". Punchy, differentiated, and memorable. Under 80 words total.',
							),
							'perplexity' => array(
								'label'  => 'Industry Positioning',
								'prompt' => 'Research the competitive landscape for a brand in the "{title}" space. Who are the top competitors, what is the market opportunity, and where does a challenger brand have room to differentiate? Cite sources.',
							),
						),
					),

				),
			),

			// ── Industrial / B2B ────────────────────────────────────────────
			'industrial-b2b' => array(
				'label'     => 'Industrial / B2B',
				'subtopics' => array(

					'service-page' => array(
						'label'  => 'Service Page',
						'panels' => array(
							'claude' => array(
								'label'  => 'Technical Service Description',
								'prompt' => 'Write a detailed service page for "{title}" targeting industrial or B2B buyers. Cover: what the service entails, technical specifications, compliance or safety considerations, industries served, and measurable outcomes. 500–700 words. Professional, authoritative tone.',
							),
							'openai' => array(
								'label'  => 'Executive Summary',
								'prompt' => 'Write a concise executive summary for an industrial service page about "{title}". Suitable for a decision-maker skimming the page. Focus on capability, reliability, and ROI. Under 120 words.',
							),
							'perplexity' => array(
								'label'  => 'Industry Standards & Data',
								'prompt' => 'Research "{title}" as an industrial or B2B service. Include: relevant industry standards and regulations, certifications typically required, average lead times or pricing benchmarks, and market demand data. Cite sources.',
							),
						),
					),

					'case-study' => array(
						'label'  => 'Case Study',
						'panels' => array(
							'claude' => array(
								'label'  => 'Technical Case Narrative',
								'prompt' => 'Write a B2B case study for "{title}". Structure: client challenge, solution delivered, technical approach, measurable results (cost savings, efficiency gains, uptime improvement, etc.). Data-driven and professional. 500–700 words.',
							),
							'openai' => array(
								'label'  => 'ROI Summary',
								'prompt' => 'Write a 2–3 sentence ROI-focused summary of a B2B case study about "{title}". Highlight the quantified outcome for the executive reader. Under 80 words.',
							),
							'perplexity' => array(
								'label'  => 'Technical Benchmarks',
								'prompt' => 'Provide technical benchmarks and industry comparisons for "{title}" in an industrial context. What are typical performance metrics, cost ranges, and success rates for this type of project or service? Cite sources.',
							),
						),
					),

					'product-spec' => array(
						'label'  => 'Product Spec',
						'panels' => array(
							'claude' => array(
								'label'  => 'Technical Product Copy',
								'prompt' => 'Write detailed product specification copy for "{title}" aimed at industrial or engineering buyers. Cover: materials, tolerances, performance range, applications, compliance, and ordering information. Technical but readable. 400–600 words.',
							),
							'openai' => array(
								'label'  => 'Overview',
								'prompt' => 'Write a short, clear product overview for "{title}" for use at the top of a spec sheet. Highlight the key capability and primary application. Under 80 words.',
							),
							'perplexity' => array(
								'label'  => 'Compliance & Standards',
								'prompt' => 'Research compliance requirements, industry standards, and technical specifications relevant to "{title}" as an industrial product. Include applicable certifications, testing standards, and regulatory requirements. Cite sources.',
							),
						),
					),

				),
			),

			// ── Healthcare ───────────────────────────────────────────────────
			'healthcare' => array(
				'label'     => 'Healthcare',
				'subtopics' => array(

					'service-page' => array(
						'label'  => 'Service Page',
						'panels' => array(
							'claude' => array(
								'label'  => 'Patient-Friendly Description',
								'prompt' => 'Write a healthcare service page for "{title}" in a warm, empathetic, and clear tone. Cover: what the service is, who it is for, what patients can expect, how to prepare, and how to schedule. Avoid jargon. 300–500 words.',
							),
							'openai' => array(
								'label'  => 'Patient Summary',
								'prompt' => 'Write a 2-sentence patient-facing summary for a healthcare service page about "{title}". Reassuring and clear. Under 60 words.',
							),
							'perplexity' => array(
								'label'  => 'Clinical Research',
								'prompt' => 'Research "{title}" as a healthcare service. Include: clinical evidence for its effectiveness, typical outcomes, how widely it is used, any relevant clinical guidelines, and what patients should know from medical literature. Cite peer-reviewed sources where possible.',
							),
						),
					),

					'condition-page' => array(
						'label'  => 'Condition Page',
						'panels' => array(
							'claude' => array(
								'label'  => 'Condition Overview',
								'prompt' => 'Write a patient-friendly overview of "{title}" as a medical condition. Cover: what it is, symptoms, causes, risk factors, how it is diagnosed, and treatment options. Empathetic and clear. Avoid alarming language. 400–600 words.',
							),
							'openai' => array(
								'label'  => 'Key Points',
								'prompt' => 'List 5 key points a patient should know about "{title}" as a medical condition. One sentence each. Plain language.',
							),
							'perplexity' => array(
								'label'  => 'Medical Research',
								'prompt' => 'Research "{title}" from a clinical perspective. Include: prevalence statistics, treatment efficacy data, recent research findings, and clinical guidelines from major medical organizations. Cite peer-reviewed sources.',
							),
						),
					),

					'faq-page' => array(
						'label'  => 'FAQ Page',
						'panels' => array(
							'claude' => array(
								'label'  => 'Patient FAQ',
								'prompt' => 'Write 6 patient FAQs about "{title}". Cover: what to expect, how to prepare, costs/insurance, recovery, risks, and alternatives. Empathetic, plain-English tone. Include answers.',
							),
							'openai' => array(
								'label'  => 'Question List',
								'prompt' => 'List 8 questions patients commonly ask about "{title}". Questions only, one per line.',
							),
							'perplexity' => array(
								'label'  => 'Medically Accurate Answers',
								'prompt' => 'Provide medically accurate, research-backed answers to common patient questions about "{title}". Include statistics, success rates, and reference clinical guidelines. Cite sources.',
							),
						),
					),

				),
			),

			// ── Legal ────────────────────────────────────────────────────────
			'legal' => array(
				'label'     => 'Legal',
				'subtopics' => array(

					'practice-area' => array(
						'label'  => 'Practice Area',
						'panels' => array(
							'claude' => array(
								'label'  => 'Practice Area Description',
								'prompt' => 'Write a practice area page for a law firm covering "{title}". Explain what this area of law involves, what types of cases are handled, who typically needs this help, and how the firm approaches these cases. Client-focused and authoritative. 400–600 words.',
							),
							'openai' => array(
								'label'  => 'Short Overview',
								'prompt' => 'Write a 2-sentence overview of the "{title}" practice area for a law firm website. Clear, client-reassuring. Under 60 words.',
							),
							'perplexity' => array(
								'label'  => 'Case Law & Statistics',
								'prompt' => 'Research "{title}" as a legal practice area. Include: relevant case law, key statutes, success rate statistics where available, how courts typically rule, and what factors most influence outcomes. Cite legal sources.',
							),
						),
					),

					'case-result' => array(
						'label'  => 'Case Result',
						'panels' => array(
							'claude' => array(
								'label'  => 'Case Narrative',
								'prompt' => 'Write a case result page for a law firm that handled a "{title}" matter. Describe: the client\'s situation, the legal challenge, the strategy used, and the outcome achieved. Professional, results-focused, without identifying the client. 400–500 words.',
							),
							'openai' => array(
								'label'  => 'Outcome Summary',
								'prompt' => 'Write a 2-sentence outcome summary for a legal case result about "{title}". Highlight the result achieved for the client. Under 60 words.',
							),
							'perplexity' => array(
								'label'  => 'Legal Precedents',
								'prompt' => 'Research legal precedents and landmark cases relevant to "{title}" matters. What case law shapes how these disputes are typically resolved? Cite legal sources.',
							),
						),
					),

					'faq-page' => array(
						'label'  => 'FAQ Page',
						'panels' => array(
							'claude' => array(
								'label'  => 'Legal FAQ',
								'prompt' => 'Write 6 FAQs for a law firm page about "{title}". Cover: what to expect, costs/fees, timelines, what factors matter most, and what clients should bring to a consultation. Approachable but authoritative.',
							),
							'openai' => array(
								'label'  => 'Question List',
								'prompt' => 'List 8 questions prospective clients commonly ask about "{title}" legal matters. Questions only, one per line.',
							),
							'perplexity' => array(
								'label'  => 'Legal Research',
								'prompt' => 'Research common legal questions related to "{title}". Include applicable statutes, how courts have ruled on typical issues, and what the law requires or permits. Cite legal sources.',
							),
						),
					),

				),
			),

			// ── Real Estate ──────────────────────────────────────────────────
			'real-estate' => array(
				'label'     => 'Real Estate',
				'subtopics' => array(

					'property-listing' => array(
						'label'  => 'Property Listing',
						'panels' => array(
							'claude' => array(
								'label'  => 'Listing Description',
								'prompt' => 'Write a compelling real estate listing description for "{title}". Highlight standout features, lifestyle appeal, location benefits, and a call to schedule a showing. Warm and vivid. 300–400 words.',
							),
							'openai' => array(
								'label'  => 'Short Pitch',
								'prompt' => 'Write a punchy 2-sentence listing teaser for "{title}". Lead with the most compelling feature. Under 60 words.',
							),
							'perplexity' => array(
								'label'  => 'Neighborhood & Market Data',
								'prompt' => 'Research the neighborhood and market context for a property listing related to "{title}". Include: school ratings, walkability, nearby amenities, median home prices, and market trend data. Cite sources.',
							),
						),
					),

					'neighborhood-guide' => array(
						'label'  => 'Neighborhood Guide',
						'panels' => array(
							'claude' => array(
								'label'  => 'Neighborhood Overview',
								'prompt' => 'Write a comprehensive neighborhood guide for "{title}". Cover: character and vibe, housing styles, schools, restaurants and shopping, parks, commute options, and who typically lives there. Warm and informative. 500–700 words.',
							),
							'openai' => array(
								'label'  => 'Highlights',
								'prompt' => 'Write 3–5 bullet-point highlights for the "{title}" neighborhood. Each should be one compelling sentence. Under 80 words total.',
							),
							'perplexity' => array(
								'label'  => 'Market & Demographics',
								'prompt' => 'Research "{title}" as a real estate neighborhood. Include: median home price, price trend over 1–3 years, demographic data, school ratings, crime index, and walkability score. Cite sources.',
							),
						),
					),

					'agent-bio' => array(
						'label'  => 'Agent Bio',
						'panels' => array(
							'claude' => array(
								'label'  => 'Professional Bio',
								'prompt' => 'Write a professional but personable real estate agent bio for "{title}". Cover: years of experience, areas of expertise, local market knowledge, what makes them different, and a human touch that builds trust. 300–400 words.',
							),
							'openai' => array(
								'label'  => 'Short Intro',
								'prompt' => 'Write a 2-sentence intro bio for real estate agent "{title}" suitable for a listings sidebar. Professional and warm. Under 50 words.',
							),
							'perplexity' => array(
								'label'  => 'Market Expertise Context',
								'prompt' => 'Research the local real estate market context for an agent bio about "{title}". What are current market conditions, trends, average days on market, and price movements in this area that an agent would want to reference? Cite sources.',
							),
						),
					),

				),
			),

		);

		return apply_filters( 'twtaeo_industry_config', $config );
	}

	/**
	 * Return a flat list of industry labels keyed by slug.
	 *
	 * @return array { slug => label }
	 */
	public static function get_industry_labels() {
		$labels = array();
		foreach ( self::get() as $slug => $industry ) {
			$labels[ $slug ] = $industry['label'];
		}
		return $labels;
	}

	/**
	 * Return subtopic labels for a given industry slug.
	 *
	 * @param string $industry_slug
	 * @return array { slug => label }
	 */
	public static function get_subtopic_labels( $industry_slug ) {
		$config = self::get();
		$labels = array();
		foreach ( $config[ $industry_slug ]['subtopics'] ?? array() as $slug => $subtopic ) {
			$labels[ $slug ] = $subtopic['label'];
		}
		return $labels;
	}
}
