# External Services — TWT AEO Ultimate

This plugin connects to outside services **only when you configure them and trigger a feature**. Nothing is sent on activation, and no site-visitor personal data is ever transmitted. Every AI and API request uses your own key or your own connected account.

This file has two parts:

1. **Services that receive data** — what is sent, when, and each provider's terms and privacy policy.
2. **Sites that are only linked** — directories and documentation the admin screens link to. No data is sent to them; clicking a link opens the site in your browser under that site's own terms.

---

## 1. Services that receive data

### AI providers (your own API keys)

| Service | Endpoint | Used by | What is sent | Terms | Privacy |
|---|---|---|---|---|---|
| **Anthropic Claude** | `api.anthropic.com` | Meta descriptions, product enrichment, PR tools, AI Visibility, RAG Engine document labels (only when switched on) | The prompt for the action you ran: page or product title and text, or an AI Visibility question. For a document label: the file name and the first ~8,000 characters of the document, once | [Terms](https://www.anthropic.com/legal/aup) | [Privacy](https://www.anthropic.com/legal/privacy) |
| **OpenAI** | `api.openai.com` | Meta descriptions, Open Graph image generation, image analysis, AI Visibility, RAG Engine document labels (only when switched on) | Same as above; for images, the image URL or prompt | [Terms](https://openai.com/policies/terms-of-use) | [Privacy](https://openai.com/policies/privacy-policy) |
| **Google Gemini** | `generativelanguage.googleapis.com` | Meta descriptions, vision, web-grounded lookups (brand links, Smart 404), AI Visibility, RAG Engine document labels (only when switched on) and law check | Same as above; for vision, the image data. For the law check: a law or code's citation, place, date and section headings — never the document's text | [Terms](https://policies.google.com/terms) | [Privacy](https://policies.google.com/privacy) |
| **Perplexity** | `api.perplexity.ai` (Agent API) | Brand and gap research, AI Visibility, RAG Engine law check | The question or lookup prompt. For the law check: a law or code's citation, place, date and section headings — never the document's text | [Terms](https://www.perplexity.ai/hub/legal/terms-of-service) | [Privacy](https://www.perplexity.ai/hub/legal/privacy-policy) |
| **xAI Grok** | `api.x.ai` | AI Visibility only | An AI Visibility question | [Terms](https://x.ai/legal/terms-of-service-enterprise) | [Privacy](https://x.ai/legal/privacy-policy) |
| **Mistral (Le Chat)** | `api.mistral.ai` | AI Visibility only | An AI Visibility question | [Terms](https://legal.mistral.ai/terms/commercial-terms-of-service) | [Privacy](https://legal.mistral.ai/terms/privacy-policy) |
| **Meta (Muse, Meta Model API)** | `api.meta.ai` | AI Visibility only | An AI Visibility question | [Terms](https://ai.developer.meta.com/legal/terms-of-service) | [Privacy](https://www.facebook.com/privacy/policy/) |
| **DeepSeek** | `api.deepseek.com` | AI Visibility only | An AI Visibility question | [Terms](https://cdn.deepseek.com/policies/en-US/deepseek-open-platform-terms-of-service.html) | [Privacy](https://cdn.deepseek.com/policies/en-US/deepseek-privacy-policy.html) |

When no plugin key is set and WordPress 7.0's built-in AI Client is available, Claude and OpenAI requests go through the provider you connected under **Settings → Connectors** instead.

### Google APIs (your own connected Google account)

Terms: [Google Terms](https://policies.google.com/terms) · [Google APIs Terms](https://developers.google.com/terms) · Privacy: [Google Privacy Policy](https://policies.google.com/privacy)

| API | Endpoint | Used by | What is sent |
|---|---|---|---|
| Google sign-in (OAuth) | `accounts.google.com`, `oauth2.googleapis.com` | Connecting a Google account | Standard OAuth authorization and token exchange |
| Search Console | `searchconsole.googleapis.com` | Not Indexed report, Command Center, RAG Engine Search Placement, the property dropdown in Settings | Your site's URLs for inspection and query reports; a request for the account's list of properties |
| Analytics (GA4) | `analyticsdata.googleapis.com`, `analyticsadmin.googleapis.com` | Command Center, traffic-leak scan | Property ID and report requests |
| PageSpeed Insights | `www.googleapis.com/pagespeedonline` | Command Center performance audits | The page URL being audited |
| Knowledge Graph Search | `kgsearch.googleapis.com` | Local SEO NAP audit, entity lookups | Your business or entity name |
| Business Profile | `mybusinessaccountmanagement.googleapis.com`, `mybusinessbusinessinformation.googleapis.com` | Local SEO NAP audit | Account and location requests |
| Merchant API | `merchantapi.googleapis.com` | Google Merchant Center sync (WooCommerce) | Product IDs, prices, availability, promotions |

### Microsoft / Bing APIs (your own connected account or key)

Terms: [Microsoft Services Agreement](https://www.microsoft.com/en-us/servicesagreement) · Privacy: [Microsoft Privacy Statement](https://www.microsoft.com/en-us/privacy/privacystatement)

| API | Endpoint | Used by | What is sent |
|---|---|---|---|
| Microsoft sign-in | `login.microsoftonline.com` | Connecting Bing Merchant Center | Standard OAuth authorization and token exchange |
| Bing Webmaster Tools | `ssl.bing.com/webmaster/api.svc` | Command Center, keyword history, RAG Engine Search Placement | Your site URL and report requests |
| Bing Merchant Center | `content.api.bingads.microsoft.com` | Bing Merchant Center sync (WooCommerce) | Product IDs, prices, availability |
| Bing Maps Local Search | `dev.virtualearth.net` | Local SEO NAP audit | Your business name and location |

### Search-engine notification

| Service | Endpoint | Used by | What is sent | Terms & Privacy |
|---|---|---|---|---|
| **IndexNow** | `api.indexnow.org` (or the engines you choose) | Publishing a post, manual submission | The published URL, your site host and the IndexNow key. Not sent from local, development or staging sites. | [IndexNow terms](https://www.indexnow.org/terms) |

### Press-release distribution (your own accounts)

| Service | Used by | What is sent | Terms | Privacy |
|---|---|---|---|---|
| **EIN Presswire** | PR Bridge | The press release you choose to distribute | [Terms](https://www.einpresswire.com/legal/terms) | [Privacy](https://www.einpresswire.com/legal/privacy) |
| **EasyPRwire** | PR Bridge | The press release you choose to distribute | [Terms](https://easyprwire.com/terms-and-condition) | [Privacy](https://easyprwire.com/privacy-policy) |

### Tampa Web Technologies (optional, opt-in)

Terms: [Plugin Terms](https://tampawebtech.com/plugin-terms/) · Privacy: [Plugin Privacy Policy](https://tampawebtech.com/plugin-privacy-policies/)

| Feature | Endpoint | What is sent |
|---|---|---|
| **Token telemetry** (off by default; Settings → Usage Telemetry) | `tampawebtech.com/wp-json/twt-aeo/v1/token-telemetry` | AI provider name, model name, token counts and plugin version. Never content, titles, URLs or site details. |
| **TWT Agency dashboard** (off by default; Settings → Pro Dashboard) | The dashboard URL you enter | Scores, scan results and events for the site you connected |

### Pages you ask the plugin to fetch

**Bot View**, **Chunk View** and the crawler tester fetch the URLs you enter or that your own site links to, the way a search crawler would. That is an ordinary web request to that URL; no key or site data is attached.

The **RAG Engine law check** loads the official-source page its search names (usually a government or code-publisher site) once, only to confirm the link works before showing it. Nothing is sent but the request for that page.

---

## 2. Sites that are only linked

The admin screens link to these sites for setup help, profile claiming and citation-building. **No data is sent to any of them** — a link opens in your browser only when you click it, and your use of each site is governed by that site's own terms and privacy policy.

**Business listings and review directories:** airbnb.com, angi.com, pro.angi.com, avvo.com, bbb.org, bingplaces.com, biz.yelp.com, booksy.com, bringfido.com, buildzoom.com, business.foursquare.com, business.google.com, business.linkedin.com, business.nextdoor.com, business.trustpilot.com, capterra.com, carfax.com, cargurus.com, cars.com, dealer.cars.com, dealer.edmunds.com, dealerrater.com, doctor.webmd.com, fresha.com, g.page, greatschools.org, healthgrades.com, homes.com, pro.homeadvisor.com, houzz.com, justia.com, lawyers.justia.com, lawyer.findlaw.com, lawyers.findlaw.com, loopnet.com, mapsconnect.apple.com, martindale.com, mindbodyonline.com, colleges.niche.com, partner.booking.com, petmd.com, ratemds.com, realtor.com, repairpal.com, restaurant.grubhub.com, restaurant.opentable.com, rover.com, sell.g2.com, sitejabber.com, styleseat.com, superlawyers.com, thumbtack.com, tripadvisor.com, trulia.com, trustradius.com, vagaro.com, vitals.com, wagwalking.com, welcome.expediagroup.com, wyzant.com, classgap.com, zillow.com, zocdoc.com, zomato.com, clutch.co

**Professional and financial directories:** cfp.net, napfa.org, nerdwallet.com, smartasset.com, wallethub.com, xyplanningnetwork.com

**Social and identity profiles:** facebook.com, instagram.com, linkedin.com, pinterest.com, twitter.com, x.com, youtube.com, gravatar.com, en.wikipedia.org, wikidata.org

**Account consoles and documentation:** console.cloud.google.com, search.google.com, google.com (the law check's "Search for the current version" link), maps.google.com, ads.microsoft.com, bing.com, developers.facebook.com, developer.wordpress.org, wordpress.org, woocommerce.com, github.com, datatracker.ietf.org, rfc-editor.org, contentsignals.org, isitagentready.com, aeoultimate.com, tampawebtech.com
