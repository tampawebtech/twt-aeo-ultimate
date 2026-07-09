# TWT AEO Ultimate — External Services & Outbound Links

This document is the complete inventory of every external service this plugin can connect to and every outbound link it can display, with each provider's terms of service and privacy policy. A summary appears in readme.txt (FAQ: "What external services does this plugin connect to?").

This plugin can optionally connect to third-party providers to power AI content generation, image (vision) analysis, structured-data enrichment, indexing, and PR distribution. Connections stay dormant unless you explicitly enable a feature and add your own API credentials in the settings panel. AI requests are only made when you click an AI action; nothing is sent automatically or on activation.

## Anthropic Claude API
* **Used by:** PR Bridge AI, AI meta descriptions, WooCommerce product enrichment (attribute extraction, description rewriting, attribute standardization), and Image SEO / product image analysis.
* **Data sent:** Your content and prompt text, plus your Anthropic API key. For image features (vision tagging, alt-text generation), the URLs and contents of your own post/product images are also sent. No visitor identity data is sent.
* **Triggers:** Only when you click an AI action (generate content, enhance a description, extract attributes, generate alt text, etc.).
* **Endpoint:** api.anthropic.com (API host — not a browsable page)
* **Resources:** [Anthropic Acceptable Use Policy](https://www.anthropic.com/legal/aup) | [Anthropic Privacy Policy](https://www.anthropic.com/legal/privacy)

## OpenAI API
* **Used by:** AI meta descriptions, AI Open Graph image generation, WooCommerce product enrichment, and Image SEO / product image analysis.
* **Data sent:** A content or image prompt and your OpenAI API key. For image analysis features, the URLs and contents of your own post/product images are also sent.
* **Triggers:** Only when you click an AI action, if an OpenAI key is configured.
* **Endpoint:** api.openai.com (API host — not a browsable page)
* **Resources:** [OpenAI Terms of Use](https://openai.com/policies/terms-of-use) | [OpenAI Privacy Policy](https://openai.com/policies/privacy-policy)

## Google Gemini API
* **Used by:** AI meta descriptions, WooCommerce product enrichment, Image SEO / product image analysis, and web-grounded brand authority (sameAs) and competitive-gap lookups.
* **Data sent:** Your content and prompt text, image URLs/contents for vision features, brand/product names for grounded lookups, and your Gemini API key. Sent to `generativelanguage.googleapis.com`. No visitor identity data is sent.
* **Triggers:** Only when you click an AI action, if a Gemini key is configured.
* **Resources:** [Google Terms of Service](https://policies.google.com/terms) | [Google Privacy Policy](https://policies.google.com/privacy)

## Perplexity AI API
* **Used by:** (optional) WooCommerce brand authority (sameAs) resolution and competitive-gap analysis.
* **Data sent:** A brand or product name, plus your Perplexity API key.
* **Triggers:** Only on explicit AI actions, if a Perplexity key is configured.
* **Endpoint:** api.perplexity.ai (API host — not a browsable page)
* **Resources:** [Perplexity Terms of Service](https://www.perplexity.ai/hub/legal/terms-of-service) | [Perplexity Privacy Policy](https://www.perplexity.ai/hub/legal/privacy-policy)

## Google APIs (OAuth 2.0, Search Console, Analytics, Knowledge Graph, Business Profile, Merchant Center, Sitemap Ping)
* **Used by:** Command Center reporting, Local Pack NAP checks, WooCommerce product sync, and sitemap notifications.
* **Data sent:** OAuth tokens, property/account identifiers, product data, or URLs — over standard Google OAuth flows. No end-user personal data is sent.
* **Triggers:** On dashboard reporting, scheduled product sync, or manual checks.
* **Endpoints:** accounts.google.com, oauth2.googleapis.com, www.googleapis.com, searchconsole.googleapis.com, analyticsadmin.googleapis.com, analyticsdata.googleapis.com, kgsearch.googleapis.com, mybusinessaccountmanagement.googleapis.com, mybusinessbusinessinformation.googleapis.com, shoppingcontent.googleapis.com. Admin pages also link out (no data sent) to Google dashboards: business.google.com, search.google.com, console.cloud.google.com, maps.google.com, g.page.
* **Resources:** [Google Terms of Service](https://policies.google.com/terms) | [Google Privacy Policy](https://policies.google.com/privacy)

## Microsoft / Bing APIs (OAuth, Merchant Center, Maps Local Search, Webmaster Tools, IndexNow)
* **Used by:** E-commerce feed sync, Local Pack NAP/address consistency checks (Bing Maps Local Search, `dev.virtualearth.net`), and indexing.
* **Data sent:** OAuth tokens, structured product data, your business name/address for NAP checks (with your Bing Maps API key), or published URLs sent to the relevant API endpoints.
* **Triggers:** On content publish/update events, catalog sync intervals, or a manual NAP check.
* **Endpoints:** login.microsoftonline.com (OAuth), ssl.bing.com (Webmaster API), content.api.bingads.microsoft.com (Merchant Center), dev.virtualearth.net (Maps Local Search). Admin pages also link out (no data sent) to Microsoft dashboards: ads.microsoft.com, www.bing.com/webmasters, www.bingplaces.com.
* **Resources:** [Microsoft Services Agreement](https://www.microsoft.com/en-us/servicesagreement) | [Microsoft Privacy Statement](https://www.microsoft.com/en-us/privacy/privacystatement)

## IndexNow Network Protocol
* **Used by:** IndexNow module.
* **Data sent:** Your IndexNow key and the public URLs of published or updated posts, sent to supporting search engines.
* **Triggers:** On post publish/update or manual submission.
* **Resources:** [IndexNow Protocol](https://www.indexnow.org/) | [IndexNow Terms & Privacy](https://www.indexnow.org/terms)

## EIN Presswire
* **Used by:** PR Bridge AI distribution.
* **Data sent:** Press release headline and body, the source post URL, and your EIN Presswire API key.
* **Triggers:** Only when you click submit on the PR Bridge page.
* **Resources:** [EIN Presswire Terms](https://www.einpresswire.com/legal/terms) | [EIN Presswire Privacy Policy](https://www.einpresswire.com/legal/privacy)

## EasyPRwire
* **Used by:** PR Bridge AI distribution (alternative provider).
* **Data sent:** Press release headline and body, the source post URL, and your EasyPRwire API key.
* **Triggers:** Only when you click submit on the PR Bridge page.
* **Resources:** [EasyPRWire Terms](https://easyprwire.com/terms-and-condition) | [EasyPRWire Privacy Policy](https://easyprwire.com/privacy-policy)

## TWT Agency (optional)
* **Used by:** Optional dashboard sync and remote monitoring.
* **Data sent:** Site AEO metrics, AI-crawler events, schema and content activity, analytics summaries, plugin/site info, and your TWT Agency API key. No site-visitor personal data is sent.
* **Triggers:** A daily snapshot plus event-driven pushes, only when a TWT Agency API key and Dashboard URL are configured.
* **Resources:** [TWT Terms of Use](https://tampawebtech.com/plugin-terms/) | [TWT Privacy Policy](https://tampawebtech.com/plugin-privacy-policies/)

## TWT AEO Token Telemetry (optional)
* **Used by:** AI features (anonymous usage reporting to improve the plugin).
* **Data sent:** AI provider name, model slug, plugin version, and token counts only. No content, URLs, titles, API keys, or visitor data.
* **Triggers:** On an AI generation, only if you opt in via Settings.
* **Endpoint:** tampawebtech.com/wp-json/twt-aeo/v1/token-telemetry (POST-only REST endpoint — not a browsable page)
* **Resources:** [TWT Privacy Policy](https://tampawebtech.com/plugin-privacy-policies/)

## Industry Directory Links (optional)
The plugin provides optional integrations with major industry directories to enhance your AEO and local-search footprint. Depending on the industry you select in the settings, the plugin may link out to the following external platforms. These are outbound links only — no data is transmitted to these services by the plugin, and you choose which industry (if any) to enable.

**Home Services**

* Angi — https://www.angi.com | [Privacy](https://legal.angi.com/#privacy-policy) | [Terms](https://legal.angi.com/#contract-skmav5s0l)
* Thumbtack — https://www.thumbtack.com | [Privacy](https://www.thumbtack.com/privacy) | [Terms](https://www.thumbtack.com/terms)
* HomeAdvisor — https://pro.homeadvisor.com | [Privacy](https://legal.angi.com/#privacy-policy) | [Terms](https://legal.angi.com/#contract-skmav5s0l)
* Houzz — https://www.houzz.com | [Privacy](https://www.houzz.com/privacyPolicy) | [Terms](https://www.houzz.com/termsOfUse)
* BuildZoom — https://www.buildzoom.com | [Privacy](https://www.buildzoom.com/privacy-policy) | [Terms](https://www.buildzoom.com/terms-of-service)

**Restaurant & Food**

* Yelp — https://biz.yelp.com | [Privacy](https://terms.yelp.com/privacy/en_us/) | [Terms](https://terms.yelp.com/tos/en_us/)
* TripAdvisor — https://www.tripadvisor.com | [Privacy](https://tripadvisor.mediaroom.com/us-privacy-policy) | [Terms](https://www.tripadvisor.com/pages/terms.html)
* OpenTable — https://restaurant.opentable.com | [Privacy](https://www.opentable.com/c/legal/privacy-policy/) | [Terms](https://www.opentable.com/c/legal/terms-and-conditions/)
* Zomato — https://www.zomato.com | [Privacy](https://www.zomato.com/policies/privacy/) | [Terms](https://www.zomato.com/policies/terms-of-service/)
* Foursquare — https://business.foursquare.com | [Privacy](https://foursquare.com/legal/privacy) | [Terms](https://foursquare.com/legal/terms)
* Grubhub — https://restaurant.grubhub.com | [Privacy](https://www.grubhub.com/legal/privacy-policy) | [Terms](https://www.grubhub.com/legal/terms-of-use)

**Legal**

* Avvo — https://www.avvo.com | [Privacy](https://www.internetbrands.com/privacy) | [Terms](https://www.internetbrands.com/ibterms)
* FindLaw — https://lawyers.findlaw.com | [Privacy](https://www.findlaw.com/company/privacy/privacy-statement.html) | [Terms](https://www.findlaw.com/company/findlaw-terms-of-service.html)
* Justia — https://lawyers.justia.com | [Privacy](https://www.justia.com/privacy-policy/) | [Terms](https://www.justia.com/terms-of-use/)
* Martindale-Hubbell — https://www.martindale.com | [Privacy](https://www.internetbrands.com/privacy) | [Terms](https://www.internetbrands.com/ibterms)
* Super Lawyers — https://www.superlawyers.com | [Privacy](https://www.internetbrands.com/privacy) | [Terms](https://www.internetbrands.com/ibterms)

**Healthcare**

* Healthgrades — https://www.healthgrades.com | [Privacy](https://www.healthgrades.com/content/privacy-policy) | [Terms](https://www.healthgrades.com/content/terms-of-use)
* WebMD — https://doctor.webmd.com | [Privacy](https://www.webmd.com/about-webmd-policies/about-privacy-policy) | [Terms](https://www.webmd.com/about-webmd-policies/about-terms-and-conditions-of-use)
* Vitals — https://www.vitals.com | [Privacy](https://www.vitals.com/privacy) | [Terms](https://www.vitals.com/terms-of-use)
* Zocdoc — https://www.zocdoc.com | [Privacy](https://www.zocdoc.com/privacy) | [Terms](https://www.zocdoc.com/terms)
* RateMDs — https://www.ratemds.com | [Privacy](https://www.ratemds.com/privacy/) | [Terms](https://www.ratemds.com/terms/)

**Real Estate**

* Zillow — https://www.zillow.com | [Privacy](https://www.zillowgroup.com/privacy-policy/) | [Terms](https://www.zillowgroup.com/terms-of-use/)
* Realtor.com — https://www.realtor.com | [Privacy](https://www.realtor.com/privacy-policy/) | [Terms](https://www.realtor.com/terms-of-service/)
* Homes.com — https://www.homes.com | [Privacy](https://www.homes.com/about/policies/) | [Terms](https://www.homes.com/about/homesterms-of-use/)
* Trulia — https://www.trulia.com | [Privacy](https://www.zillowgroup.com/privacy-policy/) | [Terms](https://www.zillowgroup.com/terms-of-use/)
* LoopNet — https://www.loopnet.com | [Privacy](https://www.costar.com/about/privacy-notice) | [Terms](https://www.loopnet.com/solutions/LoopNetTerms-of-Use)

**Automotive**

* Cars.com — https://www.cars.com | [Privacy](https://www.cars.com/about/privacy/) | [Terms](https://www.cars.com/about/terms/)
* RepairPal — https://repairpal.com | [Privacy](https://repairpal.com/privacy_policy) | [Terms](https://repairpal.com/terms_of_service)
* CarGurus — https://www.cargurus.com | [Privacy](https://www.cargurus.com/about/privacy-policy) | [Terms](https://www.cargurus.com/about/terms-of-use)
* CARFAX — https://www.carfax.com | [Privacy](https://www.carfax.com/company/privacy-statement) | [Terms](https://www.carfax.com/company/terms-of-use)
* DealerRater — https://www.dealerrater.com | [Privacy](https://help.dealerrater.com/kb/guide/en/dealerrater-privacy-notice-Peam1MNQXJ/Steps/2549540) | [Terms](https://www.dealerrater.com/info/tou/)
* Edmunds — https://dealer.edmunds.com | [Privacy](https://www.edmunds.com/about/privacy.html) | [Terms](https://www.edmunds.com/about/visitor-agreement.html)

**Beauty & Wellness**

* StyleSeat — https://www.styleseat.com | [Privacy](https://www.styleseat.com/privacy) | [Terms](https://www.styleseat.com/tos-for-professionals)
* Vagaro — https://www.vagaro.com | [Privacy](https://www.vagaro.com/pro/privacy) | [Terms](https://www.vagaro.com/pro/user-agreement)
* Fresha — https://www.fresha.com | [Privacy](https://terms.fresha.com/privacy-policy) | [Terms](https://terms.fresha.com/terms-service)
* Booksy — https://booksy.com | [Privacy](https://booksy.com/en-us/p/privacy) | [Terms](https://booksy.com/en-us/p/terms)
* Mindbody — https://www.mindbodyonline.com | [Privacy](https://www.mindbodyonline.com/company/legal/privacy-policy) | [Terms](https://www.mindbodyonline.com/company/legal/terms-of-service)

**Financial**

* NAPFA — https://www.napfa.org | [Privacy](https://www.napfa.org/privacy-policy-legal-disclaimer) | [Terms](https://www.napfa.org/privacy-policy-legal-disclaimer)
* CFP Board — https://www.cfp.net | [Privacy](https://www.cfp.net/privacy-policy) | [Terms](https://www.cfp.net/terms-of-use)
* NerdWallet — https://www.nerdwallet.com | [Privacy](https://legal.atomicvest.com/usa.privacy.de3d0277-78f7-4741-9e9f-755f6b4f03ba.pdf) | [Terms](https://www.nerdwallet.com/p/terms-of-use)
* SmartAsset — https://smartasset.com | [Privacy](https://smartasset.com/privacy) | [Terms](https://smartasset.com/terms)
* XY Planning Network — https://www.xyplanningnetwork.com | [Privacy](https://www.xyplanningnetwork.com/data-privacy-policy) | [Terms](https://www.xyplanningnetwork.com/data-privacy-policy)
* WalletHub — https://wallethub.com | [Privacy](https://wallethub.com/terms/privacy) | [Terms](https://wallethub.com/terms)

**Education**

* GreatSchools — https://www.greatschools.org | [Privacy](https://www.greatschools.org/gk/privacy/) | [Terms](https://www.greatschools.org/gk/terms/)
* Niche — https://www.niche.com | [Privacy](https://www.niche.com/about/privacy/) | [Terms](https://www.niche.com/about/terms/)
* Wyzant — https://www.wyzant.com | [Privacy](https://support.wyzant.com/policies-and-contact-us/privacy-policy-and-terms-of-use/privacy-policy/) | [Terms](https://support.wyzant.com/policies-and-contact-us/privacy-policy-and-terms-of-use/terms-of-use/)
* Classgap — https://www.classgap.com | [Privacy](https://www.classgap.com/en/info/privacy) | [Terms](https://www.classgap.com/en/info/terms)

**Lodging**

* TripAdvisor — https://www.tripadvisor.com | [Privacy](https://tripadvisor.mediaroom.com/us-privacy-policy) | [Terms](https://www.tripadvisor.com/pages/terms.html)
* Booking.com — https://partner.booking.com | [Privacy](https://www.booking.com/content/privacy.html) | [Terms](https://www.booking.com/content/privacy.html)
* Expedia — https://welcome.expediagroup.com | [Privacy](https://legal.expediagroup.com/privacy/privacy-and-cookies-statements/other/expedia-group-privacy) | [Terms](https://www.expediagroup.com/en-us/terms-of-use)
* Google Hotel Center — https://www.google.com/hotelprices/ | [Privacy](https://policies.google.com/privacy) | [Terms](https://policies.google.com/terms)
* Airbnb — https://www.airbnb.com | [Privacy](https://www.airbnb.com/help/article/2855) | [Terms](https://www.airbnb.com/help/article/2908)

**Pet Services**

* Rover — https://www.rover.com | [Privacy](https://www.rover.com/terms/privacy/) | [Terms](https://www.rover.com/terms/tos/)
* Wag! — https://www.wagwalking.com | [Privacy](https://safety.wagwalking.com/privacy) | [Terms](https://safety.wagwalking.com/terms)
* PetMD — https://www.petmd.com | [Privacy](https://www.petmd.com/petmd-privacy-policy) | [Terms](https://www.petmd.com/legal/conditions-of-use)
* Yelp — https://biz.yelp.com | [Privacy](https://terms.yelp.com/privacy/en_us/) | [Terms](https://terms.yelp.com/tos/en_us/)
* BringFido — https://www.bringfido.com | [Privacy](https://www.bringfido.com/privacy/) | [Terms](https://www.bringfido.com/terms/)

**General Business & Review Platforms**

Cross-industry platform links that appear on the Local Pack, Reviews, and Command Center pages. Outbound links only — no data is transmitted.

* Google Business Profile — https://business.google.com | [Privacy](https://policies.google.com/privacy) | [Terms](https://policies.google.com/terms)
* Bing Places — https://www.bingplaces.com | [Privacy](https://www.microsoft.com/en-us/privacy/privacystatement) | [Terms](https://www.microsoft.com/en-us/servicesagreement)
* Apple Business Connect — https://mapsconnect.apple.com | [Privacy](https://www.apple.com/legal/privacy/) | [Terms](https://www.apple.com/legal/internet-services/terms/site.html)
* Facebook (Meta) — https://www.facebook.com/business | [Privacy](https://www.facebook.com/privacy/policy/) | [Terms](https://www.facebook.com/legal/terms)
* Nextdoor — https://business.nextdoor.com | [Privacy](https://nextdoor.com/privacy_policy/) | [Terms](https://nextdoor.com/member_agreement/)
* LinkedIn — https://business.linkedin.com | [Privacy](https://www.linkedin.com/legal/privacy-policy) | [Terms](https://www.linkedin.com/legal/user-agreement)
* Trustpilot — https://business.trustpilot.com | [Privacy](https://corporate.trustpilot.com/legal/for-reviewers/privacy-policy-end-user) | [Terms](https://corporate.trustpilot.com/legal/for-businesses/terms-of-use-and-sale-for-businesses)
* Better Business Bureau — https://www.bbb.org | [Privacy](https://www.bbb.org/privacy-policy) | [Terms](https://www.bbb.org/terms-of-use)
* Sitejabber — https://www.sitejabber.com | [Privacy](https://www.sitejabber.com/privacy) | [Terms](https://www.sitejabber.com/terms)

**B2B & Software Reviews**

* Clutch — https://clutch.co | [Privacy](https://clutch.co/privacy) | [Terms](https://clutch.co/terms)
* G2 — https://sell.g2.com | [Privacy](https://legal.g2.com/privacy-policy) | [Terms](https://legal.g2.com/terms-of-use)
* Capterra — https://www.capterra.com | [Privacy](https://www.capterra.com/legal/privacy-policy) | [Terms](https://www.capterra.com/legal/terms-of-use)
* TrustRadius — https://www.trustradius.com | [Privacy](https://www.trustradius.com/static/privacy-policy) | [Terms](https://www.trustradius.com/static/terms-of-use)

**Social Profile Links**

Social platform URLs appear as profile-link fields and in `sameAs` schema output for profiles you enter. Outbound links only — no data is transmitted.

* Instagram — https://www.instagram.com | [Privacy](https://privacycenter.instagram.com/policy) | [Terms](https://help.instagram.com/581066165581870)
* Pinterest — https://www.pinterest.com | [Privacy](https://policy.pinterest.com/en/privacy-policy) | [Terms](https://policy.pinterest.com/en/terms-of-service)
* X (Twitter) — https://x.com (formerly twitter.com) | [Privacy](https://x.com/en/privacy) | [Terms](https://x.com/en/tos)
* YouTube — https://www.youtube.com | [Privacy](https://policies.google.com/privacy) | [Terms](https://www.youtube.com/t/terms)
* Facebook / Meta developer docs — https://developers.facebook.com | [Privacy](https://www.facebook.com/privacy/policy/) | [Terms](https://www.facebook.com/legal/terms)

**Documentation & Standards References**

Links to specifications and documentation that appear in plugin help text and generated discovery files. These are references only — the plugin sends no data to them.

* Schema.org — https://schema.org | [Terms](https://schema.org/docs/terms.html)
* Wikipedia / Wikidata (Wikimedia) — https://en.wikipedia.org, https://www.wikidata.org | [Privacy](https://foundation.wikimedia.org/wiki/Policy:Privacy_policy) | [Terms](https://foundation.wikimedia.org/wiki/Policy:Terms_of_Use)
* IETF / RFC Editor — https://datatracker.ietf.org, https://www.rfc-editor.org | [Privacy](https://www.ietf.org/privacy-statement/)
* Content Signals — https://contentsignals.org (robots.txt policy specification)
* WordPress.org developer documentation — https://developer.wordpress.org | [Privacy](https://wordpress.org/about/privacy/)
* GitHub — [Privacy](https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement) | [Terms](https://docs.github.com/en/site-policy/github-terms/github-terms-of-service)
* Gravatar (Automattic) — https://gravatar.com | [Privacy](https://automattic.com/privacy/) | [Terms](https://wordpress.com/tos/)
* IsItAgentReady — https://isitagentready.com (agent-readiness checker; also used as the link-relation identifier for agent-skills discovery) | [Privacy](https://www.cloudflare.com/privacypolicy/) | [Terms](https://www.cloudflare.com/website-terms/)
