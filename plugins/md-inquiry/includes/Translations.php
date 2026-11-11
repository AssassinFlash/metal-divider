<?php
/**
 * Translation dictionary and multilingual resolvers for md-inquiry.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry;

use MetalDivider\Inquiry\Mail\Emails;

/**
 * Provides 9-language translations for all customer-visible strings in md-inquiry:
 * form fields, placeholders, guidance hints, validation errors, options labels,
 * WhatsApp prefill phrases, and confirmation email templates.
 */
final class Translations {

	/**
	 * Customer-visible select option labels by locale and English value.
	 *
	 * @var array<string, array<string, string>>
	 */
	public const OPTIONS = array(
		'zh'    => array(
			// Countries.
			'United States'                  => '美国',
			'United Kingdom'                 => '英国',
			'United Arab Emirates'           => '阿联酋',
			'Canada'                         => '加拿大',
			'Australia'                      => '澳大利亚',
			'Saudi Arabia'                   => '沙特阿拉伯',
			'Qatar'                          => '卡塔尔',
			'Singapore'                      => '新加坡',
			'Hong Kong'                      => '中国香港',
			'Malaysia'                       => '马来西亚',
			'Germany'                        => '德国',
			'France'                         => '法国',
			'Netherlands'                    => '荷兰',
			'Other'                          => '其他国家/地区',
			// Scopes.
			'Sample panel'                   => '打样样板',
			'Single zone'                    => '单一区域',
			'Multi-zone project'             => '多区域项目',
			'Full facade package'            => '整套建筑幕墙工程',
			// Routes.
			'Facade screening'               => '建筑幕墙屏风',
			'Interior partitions'            => '室内隔断屏风',
			'Outdoor privacy screens'        => '户外隐私屏风',
			'Feature walls'                  => '特色背景墙',
			'Custom fabrication'             => '定制深化加工',
			// Quantities.
			'Sample / test panel (1-10 sqm)' => '打样 / 测试样板 (1-10 平方米)',
			'10-50 sqm'                      => '10-50 平方米',
			'50-100 sqm'                     => '50-100 平方米',
			'100-250 sqm'                    => '100-250 平方米',
			'250+ sqm'                       => '250+ 平方米',
			'Not decided yet'                => '待定',
		),
		'es'    => array(
			// Countries.
			'United States'                  => 'Estados Unidos',
			'United Kingdom'                 => 'Reino Unido',
			'United Arab Emirates'           => 'Emiratos Árabes Unidos',
			'Canada'                         => 'Canadá',
			'Australia'                      => 'Australia',
			'Saudi Arabia'                   => 'Arabia Saudita',
			'Qatar'                          => 'Catar',
			'Singapore'                      => 'Singapur',
			'Hong Kong'                      => 'Hong Kong',
			'Malaysia'                       => 'Malasia',
			'Germany'                        => 'Alemania',
			'France'                         => 'Francia',
			'Netherlands'                    => 'Países Bajos',
			'Other'                          => 'Otro',
			// Scopes.
			'Sample panel'                   => 'Panel de muestra',
			'Single zone'                    => 'Zona única',
			'Multi-zone project'             => 'Proyecto multizona',
			'Full facade package'            => 'Paquete completo de fachada',
			// Routes.
			'Facade screening'               => 'Celosías para fachadas',
			'Interior partitions'            => 'Separadores de interiores',
			'Outdoor privacy screens'        => 'Pantallas de privacidad exterior',
			'Feature walls'                  => 'Muros destacados',
			'Custom fabrication'             => 'Fabricación a medida',
			// Quantities.
			'Sample / test panel (1-10 sqm)' => 'Muestra / panel de prueba (1-10 m²)',
			'10-50 sqm'                      => '10-50 m²',
			'50-100 sqm'                     => '50-100 m²',
			'100-250 sqm'                    => '100-250 m²',
			'250+ sqm'                       => '250+ m²',
			'Not decided yet'                => 'Aún no decidido',
		),
		'pt-br' => array(
			// Countries.
			'United States'                  => 'Estados Unidos',
			'United Kingdom'                 => 'Reino Unido',
			'United Arab Emirates'           => 'Emirados Árabes Unidos',
			'Canada'                         => 'Canadá',
			'Australia'                      => 'Austrália',
			'Saudi Arabia'                   => 'Arábia Saudita',
			'Qatar'                          => 'Catar',
			'Singapore'                      => 'Singapura',
			'Hong Kong'                      => 'Hong Kong',
			'Malaysia'                       => 'Malásia',
			'Germany'                        => 'Alemanha',
			'France'                         => 'França',
			'Netherlands'                    => 'Países Baixos',
			'Other'                          => 'Outro',
			// Scopes.
			'Sample panel'                   => 'Painel de amostra',
			'Single zone'                    => 'Zona única',
			'Multi-zone project'             => 'Projeto multizona',
			'Full facade package'            => 'Pacote completo de fachada',
			// Routes.
			'Facade screening'               => 'Brises e fachadas',
			'Interior partitions'            => 'Divisórias internas',
			'Outdoor privacy screens'        => 'Painéis de privacidade externa',
			'Feature walls'                  => 'Paredes decorativas',
			'Custom fabrication'             => 'Fabricação sob medida',
			// Quantities.
			'Sample / test panel (1-10 sqm)' => 'Amostra / painel de teste (1-10 m²)',
			'10-50 sqm'                      => '10-50 m²',
			'50-100 sqm'                     => '50-100 m²',
			'100-250 sqm'                    => '100-250 m²',
			'250+ sqm'                       => '250+ m²',
			'Not decided yet'                => 'Ainda não decidido',
		),
		'de'    => array(
			// Countries.
			'United States'                  => 'Vereinigte Staaten',
			'United Kingdom'                 => 'Vereinigtes Königreich',
			'United Arab Emirates'           => 'Vereinigte Arabische Emirate',
			'Canada'                         => 'Kanada',
			'Australia'                      => 'Australien',
			'Saudi Arabia'                   => 'Saudi-Arabien',
			'Qatar'                          => 'Katar',
			'Singapore'                      => 'Singapur',
			'Hong Kong'                      => 'Hongkong',
			'Malaysia'                       => 'Malaysia',
			'Germany'                        => 'Deutschland',
			'France'                         => 'Frankreich',
			'Netherlands'                    => 'Niederlande',
			'Other'                          => 'Anderes',
			// Scopes.
			'Sample panel'                   => 'Musterplatte',
			'Single zone'                    => 'Einzelner Bereich',
			'Multi-zone project'             => 'Mehrzonenprojekt',
			'Full facade package'            => 'Komplettes Fassadenpaket',
			// Routes.
			'Facade screening'               => 'Fassadenverkleidung',
			'Interior partitions'            => 'Innenraumteiler',
			'Outdoor privacy screens'        => 'Sichtschutz für den Außenbereich',
			'Feature walls'                  => 'Akzentwände',
			'Custom fabrication'             => 'Sonderanfertigung',
			// Quantities.
			'Sample / test panel (1-10 sqm)' => 'Muster- / Testpaneel (1-10 m²)',
			'10-50 sqm'                      => '10-50 m²',
			'50-100 sqm'                     => '50-100 m²',
			'100-250 sqm'                    => '100-250 m²',
			'250+ sqm'                       => '250+ m²',
			'Not decided yet'                => 'Noch nicht festgelegt',
		),
		'fr'    => array(
			// Countries.
			'United States'                  => 'États-Unis',
			'United Kingdom'                 => 'Royaume-Uni',
			'United Arab Emirates'           => 'Émirats arabes unis',
			'Canada'                         => 'Canada',
			'Australia'                      => 'Australie',
			'Saudi Arabia'                   => 'Arabie saoudite',
			'Qatar'                          => 'Qatar',
			'Singapore'                      => 'Singapour',
			'Hong Kong'                      => 'Hong Kong',
			'Malaysia'                       => 'Malaisie',
			'Germany'                        => 'Allemagne',
			'France'                         => 'France',
			'Netherlands'                    => 'Pays-Bas',
			'Other'                          => 'Autre',
			// Scopes.
			'Sample panel'                   => 'Panneau d\'échantillon',
			'Single zone'                    => 'Zone unique',
			'Multi-zone project'             => 'Projet multizone',
			'Full facade package'            => 'Ensemble de façade complet',
			// Routes.
			'Facade screening'               => 'Bardage de façade',
			'Interior partitions'            => 'Cloisons intérieures',
			'Outdoor privacy screens'        => 'Écrans d\'intimité extérieurs',
			'Feature walls'                  => 'Murs décoratifs',
			'Custom fabrication'             => 'Fabrication sur mesure',
			// Quantities.
			'Sample / test panel (1-10 sqm)' => 'Échantillon / panneau d\'essai (1-10 m²)',
			'10-50 sqm'                      => '10-50 m²',
			'50-100 sqm'                     => '50-100 m²',
			'100-250 sqm'                    => '100-250 m²',
			'250+ sqm'                       => '250+ m²',
			'Not decided yet'                => 'Pas encore décidé',
		),
		'ar'    => array(
			// Countries.
			'United States'                  => 'الولايات المتحدة',
			'United Kingdom'                 => 'المملكة المتحدة',
			'United Arab Emirates'           => 'الإمارات العربية المتحدة',
			'Canada'                         => 'كندا',
			'Australia'                      => 'أستراليا',
			'Saudi Arabia'                   => 'المملكة العربية السعودية',
			'Qatar'                          => 'قطر',
			'Singapore'                      => 'سنغافورة',
			'Hong Kong'                      => 'هونغ كونغ',
			'Malaysia'                       => 'ماليزيا',
			'Germany'                        => 'ألمانيا',
			'France'                         => 'فرنسا',
			'Netherlands'                    => 'هولندا',
			'Other'                          => 'أخرى',
			// Scopes.
			'Sample panel'                   => 'لوحة عينة',
			'Single zone'                    => 'منطقة واحدة',
			'Multi-zone project'             => 'مشروع متعدد المناطق',
			'Full facade package'            => 'حزمة واجهة كاملة',
			// Routes.
			'Facade screening'               => 'حواجز الواجهات',
			'Interior partitions'            => 'قواطع داخلية',
			'Outdoor privacy screens'        => 'شاشات خصوصية خارجية',
			'Feature walls'                  => 'جدران مميزة',
			'Custom fabrication'             => 'تصنيع مخصص',
			// Quantities.
			'Sample / test panel (1-10 sqm)' => 'عينة / لوحة اختبار (1-10 متر مربع)',
			'10-50 sqm'                      => '10-50 متر مربع',
			'50-100 sqm'                     => '50-100 متر مربع',
			'100-250 sqm'                    => '100-250 متر مربع',
			'250+ sqm'                       => '250+ متر مربع',
			'Not decided yet'                => 'لم يتقرر بعد',
		),
		'id'    => array(
			// Countries.
			'United States'                  => 'Amerika Serikat',
			'United Kingdom'                 => 'Inggris',
			'United Arab Emirates'           => 'Uni Emirat Arab',
			'Canada'                         => 'Kanada',
			'Australia'                      => 'Australia',
			'Saudi Arabia'                   => 'Arab Saudi',
			'Qatar'                          => 'Qatar',
			'Singapore'                      => 'Singapura',
			'Hong Kong'                      => 'Hong Kong',
			'Malaysia'                       => 'Malaysia',
			'Germany'                        => 'Jerman',
			'France'                         => 'Prancis',
			'Netherlands'                    => 'Belanda',
			'Other'                          => 'Lainnya',
			// Scopes.
			'Sample panel'                   => 'Panel sampel',
			'Single zone'                    => 'Zona tunggal',
			'Multi-zone project'             => 'Proyek multi-zona',
			'Full facade package'            => 'Paket fasad lengkap',
			// Routes.
			'Facade screening'               => 'Penyekat fasad',
			'Interior partitions'            => 'Partisi interior',
			'Outdoor privacy screens'        => 'Penyekat privasi luar ruangan',
			'Feature walls'                  => 'Dinding aksen',
			'Custom fabrication'             => 'Fabrikasi khusus',
			// Quantities.
			'Sample / test panel (1-10 sqm)' => 'Sampel / panel uji (1-10 m²)',
			'10-50 sqm'                      => '10-50 m²',
			'50-100 sqm'                     => '50-100 m²',
			'100-250 sqm'                    => '100-250 m²',
			'250+ sqm'                       => '250+ m²',
			'Not decided yet'                => 'Belum diputuskan',
		),
		'vi'    => array(
			// Countries.
			'United States'                  => 'Hoa Kỳ',
			'United Kingdom'                 => 'Vương quốc Anh',
			'United Arab Emirates'           => 'Các Tiểu vương quốc Ả Rập Thống nhất',
			'Canada'                         => 'Canada',
			'Australia'                      => 'Úc',
			'Saudi Arabia'                   => 'Ả Rập Xê Út',
			'Qatar'                          => 'Qatar',
			'Singapore'                      => 'Singapore',
			'Hong Kong'                      => 'Hồng Kông',
			'Malaysia'                       => 'Malaysia',
			'Germany'                        => 'Đức',
			'France'                         => 'Pháp',
			'Netherlands'                    => 'Hà Lan',
			'Other'                          => 'Khác',
			// Scopes.
			'Sample panel'                   => 'Tấm mẫu',
			'Single zone'                    => 'Khu vực đơn',
			'Multi-zone project'             => 'Dự án nhiều khu vực',
			'Full facade package'            => 'Gói mặt dựng hoàn chỉnh',
			// Routes.
			'Facade screening'               => 'Mặt dựng ngoại thất',
			'Interior partitions'            => 'Vách ngăn nội thất',
			'Outdoor privacy screens'        => 'Vách ngăn ngoài trời',
			'Feature walls'                  => 'Vách trang trí điểm nhấn',
			'Custom fabrication'             => 'Gia công theo yêu cầu',
			// Quantities.
			'Sample / test panel (1-10 sqm)' => 'Mẫu thử (1-10 m²)',
			'10-50 sqm'                      => '10-50 m²',
			'50-100 sqm'                     => '50-100 m²',
			'100-250 sqm'                    => '100-250 m²',
			'250+ sqm'                       => '250+ m²',
			'Not decided yet'                => 'Chưa xác định',
		),
	);

	/**
	 * General customer-visible translation dictionary.
	 *
	 * @var array<string, array<string, string>>
	 */
	public const STRINGS = array(
		'zh'    => array(
			// Field labels & placeholders.
			'Name'                                         => '姓名',
			'Jane Chan'                                    => '陈女士 / 先生',
			'Email'                                        => '电子邮箱',
			'Phone / WhatsApp (optional)'                  => '电话 / WhatsApp (选填)',
			'Message (optional — the pattern is already attached)' => '留言 (选填 — 已附带图案上下文)',
			'Message'                                      => '留言',
			'Dimensions, panel count, indoor or outdoor application, finish preference...' => '尺寸规格、面板数量、室内或户外应用、表面处理偏好...',
			'Quantities, dimensions, timeline, finish preferences — anything you already have. Have a drawing, DXF, DWG or PDF? Send it in reply to the confirmation email and it reaches the same engineer.' => '数量、尺寸、工期、表面工艺要求等任何现有信息。如已有图纸、DXF、DWG 或 PDF 文件，可直接回复确认邮件，图纸将直达负责您项目的工程师。',
			'Project details (company, country, scope, quantity — optional)' => '项目详情 (公司、国家/地区、项目规模、需求数量 — 选填)',
			'Company (optional)'                           => '公司 (选填)',
			'Chan Design Studio'                           => '设计事务所 / 装饰工程公司',
			'Country (optional)'                           => '国家/地区 (选填)',
			'Select country (optional)'                    => '选择国家/地区 (选填)',
			'Project scope (optional)'                     => '项目规模 (选填)',
			'Select project scope (optional)'              => '选择项目规模 (选填)',
			'Inquiry route (optional)'                     => '询盘路线 (选填)',
			'Select inquiry route (optional)'              => '选择询盘路线 (选填)',
			'Estimated quantity (optional)'                => '数量估计 (选填)',
			'Select quantity range (optional)'             => '选择数量范围 (选填)',
			'I confirm this inquiry is for an architectural or commercial project. (optional)' => '我确认此询盘适用于建筑或商业项目。(选填)',
			'Leave this field empty'                       => '请将此项留空',
			'This pattern is attached to your inquiry.'    => '此图案已附带至您的询盘。',
			'Selected pattern'                             => '已选图案',
			'Selected request'                             => '已选需求',
			'We reply within 24 hours on business days. Your details are used only to answer this inquiry — we do not share them.' => '我们在工作日 24 小时内回复。您的信息仅用于跟进此项询盘，绝不向第三方透露。',
			'The WhatsApp button opens a chat pre-filled with your details and this pattern. Choosing WhatsApp does not submit this form.' => '点击 WhatsApp 按钮将开启预填好您信息及当前图案的对话。选择 WhatsApp 不会提交此表单。',
			'The WhatsApp button opens a chat pre-filled with your details. Choosing WhatsApp does not submit this form.' => '点击 WhatsApp 按钮将开启预填好您信息的对话。选择 WhatsApp 不会提交此表单。',
			'Send inquiry'                                 => '发送询盘',
			'Send via WhatsApp instead'                    => '改用 WhatsApp 咨询',
			'WhatsApp'                                     => 'WhatsApp',
			'Language'                                     => '语言',
			// Validation errors.
			'Please enter your name.'                      => '请输入您的姓名。',
			'Please enter a valid email address.'          => '请输入有效的电子邮箱地址。',
			'Please choose your country.'                  => '请选择您的国家/地区。',
			'Please choose the project scope.'             => '请选择项目规模。',
			'Please choose an inquiry route.'              => '请选择询盘路线。',
			'Please choose a quantity range.'              => '请选择数量范围。',
			'Please add a short message.'                  => '请填写简要需求留言。',
			'Please confirm this inquiry is for an architectural or commercial project.' => '请确认此询盘适用于建筑或商业项目。',
			'Please complete this field.'                  => '请填写此字段。',
			// Notices.
			'You\'re sending inquiries too quickly. Please wait a few minutes and try again.' => '您的询盘发送过于频繁，请稍候几分钟再试。',
			'Your form session expired. Please reload the page and try again.' => '表单会话已过期，请刷新页面后重试。',
			'Verification failed. Please refresh the page and try again.' => '安全验证未通过，请刷新页面后重试。',
			'We could not accept this submission. If this keeps happening, reach us on WhatsApp or by email.' => '无法接收此提交。若持续出现此情况，请通过 WhatsApp 或邮件直接与我们联系。',
			'Your inquiry could not be saved right now. Please try again, or reach us on WhatsApp or by email.' => '暂时无法保存您的询盘，请稍后重试，或通过 WhatsApp 或邮件直接与我们联系。',
			'Please complete the highlighted fields.'      => '请完整填写高亮标出的字段。',
			'Verification expired. Please try again.'      => '验证已过期，请重试。',
			'Verification could not run. Please refresh the page and try again.' => '无法运行安全验证，请刷新页面后重试。',
			// JS UI & WhatsApp greetings.
			'Request a quote · %s'                         => '获取报价 · %s',
			'Request a quote'                              => '获取报价',
			'Hi, I\'m %s%s.'                               => '您好，我是 %s%s。',
			'I\'d like a quote for this pattern:'          => '我想咨询这款图案的报价：',
			'Inquiry: '                                    => '询盘需求：',
			'Hi Metal Divider, I\'d like to discuss a stainless steel screen project.' => '您好 Metal Divider，我想咨询定制不锈钢金属屏风项目报价。',
			'Hi, I\'m interested in a custom metal divider screen project.' => '您好 Metal Divider，我想咨询定制不锈钢金属屏风项目报价。',
			'Hi Metal Divider, I\'d like a quote for the %s pattern.' => '您好 Metal Divider，我想咨询 %s 图案的定制报价。',
			// Quote intents.
			'CAD & Shop Drawing Review'                    => 'CAD 与深化施工图纸评估',
			'Custom Pattern Brief'                         => '专属定制图案需求',
			'Quote Similar Partition Project Spec'         => '咨询同款隔断工程规格报价',
			'Quote Similar Project Specification'          => '咨询同款项目工程规格报价',
			'Quote Similar Spec — Coastal Privacy Screens' => '咨询同款规格 — 滨海户外隐私屏风',
			'Quote Similar Spec — Commercial Screening'    => '咨询同款规格 — 商业空间屏风',
			'Quote Similar Spec — Facade Screens'          => '咨询同款规格 — 建筑立面幕墙屏风',
			'Quote Similar Spec — Hospitality Feature Wall' => '咨询同款规格 — 酒店餐饮特色背景墙',
			'Quote Similar Spec — Hotel Lobby Screens'     => '咨询同款规格 — 酒店大堂金属屏风',
			'Quote Similar Spec — Office Dividers'         => '咨询同款规格 — 办公空间金属隔断',
			'Quote Similar Spec — PVD Finish Batch'        => '咨询同款规格 — PVD 真空镀色批次定制',
			'Quote Similar Spec — Residential Divider'     => '咨询同款规格 — 住宅空间定制隔断',
			'Request Physical SS 304/316 Finish Samples'   => '申领 304 / 316 不锈钢实物表面样板',
		),
		'es'    => array(
			// Field labels & placeholders.
			'Name'                                         => 'Nombre',
			'Jane Chan'                                    => 'María García',
			'Email'                                        => 'Correo electrónico',
			'Phone / WhatsApp (optional)'                  => 'Teléfono / WhatsApp (opcional)',
			'Message (optional — the pattern is already attached)' => 'Mensaje (opcional — el patrón ya está adjunto)',
			'Message'                                      => 'Mensaje',
			'Dimensions, panel count, indoor or outdoor application, finish preference...' => 'Dimensiones, cantidad de paneles, aplicación interior o exterior, acabado...',
			'Quantities, dimensions, timeline, finish preferences — anything you already have. Have a drawing, DXF, DWG or PDF? Send it in reply to the confirmation email and it reaches the same engineer.' => 'Cantidades, dimensiones, plazos, preferencias de acabado: cualquier información que tenga. ¿Dispone de planos, DXF, DWG o PDF? Envíelos respondiendo al correo de confirmación y llegarán al mismo ingeniero.',
			'Project details (company, country, scope, quantity — optional)' => 'Detalles del proyecto (empresa, país, alcance, cantidad — opcional)',
			'Company (optional)'                           => 'Empresa (opcional)',
			'Chan Design Studio'                           => 'Estudio de Arquitectura',
			'Country (optional)'                           => 'País (opcional)',
			'Select country (optional)'                    => 'Seleccionar país (opcional)',
			'Project scope (optional)'                     => 'Alcance del proyecto (opcional)',
			'Select project scope (optional)'              => 'Seleccionar alcance del proyecto (opcional)',
			'Inquiry route (optional)'                     => 'Ruta de consulta (opcional)',
			'Select inquiry route (optional)'              => 'Seleccionar ruta de consulta (opcional)',
			'Estimated quantity (optional)'                => 'Cantidad estimada (opcional)',
			'Select quantity range (optional)'             => 'Seleccionar rango de cantidad (opcional)',
			'I confirm this inquiry is for an architectural or commercial project. (optional)' => 'Confirmo que esta consulta es para un proyecto arquitectónico o comercial. (opcional)',
			'Leave this field empty'                       => 'Deje este campo vacío',
			'This pattern is attached to your inquiry.'    => 'Este patrón se adjunta a su consulta.',
			'Selected pattern'                             => 'Patrón seleccionado',
			'Selected request'                             => 'Solicitud seleccionada',
			'We reply within 24 hours on business days. Your details are used only to answer this inquiry — we do not share them.' => 'Respondemos en 24 horas en días laborables. Sus datos solo se utilizan para responder a esta consulta — no los compartimos.',
			'The WhatsApp button opens a chat pre-filled with your details and this pattern. Choosing WhatsApp does not submit this form.' => 'El botón de WhatsApp abre un chat con sus datos y este patrón precargados. Elegir WhatsApp no envía este formulario.',
			'The WhatsApp button opens a chat pre-filled with your details. Choosing WhatsApp does not submit this form.' => 'El botón de WhatsApp abre un chat con sus datos precargados. Elegir WhatsApp no envía este formulario.',
			'Send inquiry'                                 => 'Enviar consulta',
			'Send via WhatsApp instead'                    => 'Enviar por WhatsApp',
			'WhatsApp'                                     => 'WhatsApp',
			'Language'                                     => 'Idioma',
			// Validation errors.
			'Please enter your name.'                      => 'Por favor, introduzca su nombre.',
			'Please enter a valid email address.'          => 'Por favor, introduzca una dirección de correo electrónico válida.',
			'Please choose your country.'                  => 'Por favor, elija su país.',
			'Please choose the project scope.'             => 'Por favor, elija el alcance del proyecto.',
			'Please choose an inquiry route.'              => 'Por favor, elija una ruta de consulta.',
			'Please choose a quantity range.'              => 'Por favor, elija un rango de cantidad.',
			'Please add a short message.'                  => 'Por favor, añada un breve mensaje.',
			'Please confirm this inquiry is for an architectural or commercial project.' => 'Por favor, confirme que esta consulta es para un proyecto arquitectónico o comercial.',
			'Please complete this field.'                  => 'Por favor, complete este campo.',
			// Notices.
			'You\'re sending inquiries too quickly. Please wait a few minutes and try again.' => 'Está enviando consultas con demasiada rapidez. Espere unos minutos y vuelva a intentarlo.',
			'Your form session expired. Please reload the page and try again.' => 'La sesión del formulario ha caducado. Recargue la página y vuelva a intentarlo.',
			'Verification failed. Please refresh the page and try again.' => 'Error de verificación. Actualice la página y vuelva a intentarlo.',
			'We could not accept this submission. If this keeps happening, reach us on WhatsApp or by email.' => 'No pudimos aceptar este envío. Si esto sigue ocurriendo, contáctenos por WhatsApp o correo electrónico.',
			'Your inquiry could not be saved right now. Please try again, or reach us on WhatsApp or by email.' => 'No se pudo guardar su consulta en este momento. Inténtelo de nuevo o contáctenos por WhatsApp o correo electrónico.',
			'Please complete the highlighted fields.'      => 'Por favor, complete los campos resaltados.',
			'Verification expired. Please try again.'      => 'La verificación ha caducado. Vuelva a intentarlo.',
			'Verification could not run. Please refresh the page and try again.' => 'No se pudo ejecutar la verificación. Actualice la página y vuelva a intentarlo.',
			// JS UI & WhatsApp greetings.
			'Request a quote · %s'                         => 'Solicitar presupuesto · %s',
			'Request a quote'                              => 'Solicitar presupuesto',
			'Hi, I\'m %s%s.'                               => 'Hola, soy %s%s.',
			'I\'d like a quote for this pattern:'          => 'Me gustaría solicitar un presupuesto para este patrón:',
			'Inquiry: '                                    => 'Consulta: ',
			'Hi Metal Divider, I\'d like to discuss a stainless steel screen project.' => 'Hola Metal Divider, me gustaría hablar sobre un proyecto de celosías de acero inoxidable.',
			'Hi, I\'m interested in a custom metal divider screen project.' => 'Hola Metal Divider, me gustaría solicitar un presupuesto para un proyecto personalizado de celosías metálicas en acero inoxidable.',
			'Hi Metal Divider, I\'d like a quote for the %s pattern.' => 'Hola Metal Divider, me gustaría solicitar un presupuesto personalizado para el patrón %s.',
			// Quote intents.
			'CAD & Shop Drawing Review'                    => 'Revisión de planos CAD y de taller',
			'Custom Pattern Brief'                         => 'Especificación de patrón a medida',
			'Quote Similar Partition Project Spec'         => 'Cotizar especificación de proyecto de partición similar',
			'Quote Similar Project Specification'          => 'Cotizar especificación de proyecto similar',
			'Quote Similar Spec — Coastal Privacy Screens' => 'Cotizar especificación similar — Celosías de privacidad costera',
			'Quote Similar Spec — Commercial Screening'    => 'Cotizar especificación similar — Cerramientos comerciales',
			'Quote Similar Spec — Facade Screens'          => 'Cotizar especificación similar — Celosías de fachada',
			'Quote Similar Spec — Hospitality Feature Wall' => 'Cotizar especificación similar — Muro decorativo para hostelería',
			'Quote Similar Spec — Hotel Lobby Screens'     => 'Cotizar especificación similar — Celosías para vestíbulo de hotel',
			'Quote Similar Spec — Office Dividers'         => 'Cotizar especificación similar — Separadores de oficina',
			'Quote Similar Spec — PVD Finish Batch'        => 'Cotizar especificación similar — Lote de acabado PVD',
			'Quote Similar Spec — Residential Divider'     => 'Cotizar especificación similar — Separador residencial',
			'Request Physical SS 304/316 Finish Samples'   => 'Solicitar muestras físicas de acabados en acero inoxidable 304 / 316',
		),
		'pt-br' => array(
			// Field labels & placeholders.
			'Name'                                         => 'Nome',
			'Jane Chan'                                    => 'Maria Silva',
			'Email'                                        => 'E-mail',
			'Phone / WhatsApp (optional)'                  => 'Telefone / WhatsApp (opcional)',
			'Message (optional — the pattern is already attached)' => 'Mensagem (opcional — o padrão já está anexado)',
			'Message'                                      => 'Mensagem',
			'Dimensions, panel count, indoor or outdoor application, finish preference...' => 'Dimensões, número de painéis, aplicação interna ou externa, acabamento...',
			'Quantities, dimensions, timeline, finish preferences — anything you already have. Have a drawing, DXF, DWG or PDF? Send it in reply to the confirmation email and it reaches the same engineer.' => 'Quantidades, dimensões, cronograma, preferências de acabamento: tudo o que você já tiver. Tem um desenho, DXF, DWG ou PDF? Envie em resposta ao e-mail de confirmação e ele chegará ao mesmo engenheiro.',
			'Project details (company, country, scope, quantity — optional)' => 'Detalhes do projeto (empresa, país, escopo, quantidade — opcional)',
			'Company (optional)'                           => 'Empresa (opcional)',
			'Chan Design Studio'                           => 'Estúdio de Design',
			'Country (optional)'                           => 'País (opcional)',
			'Select country (optional)'                    => 'Selecionar país (opcional)',
			'Project scope (optional)'                     => 'Escopo do projeto (opcional)',
			'Select project scope (optional)'              => 'Selecionar escopo do projeto (opcional)',
			'Inquiry route (optional)'                     => 'Tipo de aplicação (opcional)',
			'Select inquiry route (optional)'              => 'Selecionar tipo de aplicação (opcional)',
			'Estimated quantity (optional)'                => 'Quantidade estimada (opcional)',
			'Select quantity range (optional)'             => 'Selecionar faixa de quantidade (opcional)',
			'I confirm this inquiry is for an architectural or commercial project. (optional)' => 'Confirmo que esta consulta é para um projeto arquitetônico ou comercial. (opcional)',
			'Leave this field empty'                       => 'Deixe este campo em branco',
			'This pattern is attached to your inquiry.'    => 'Este padrão está anexado à sua consulta.',
			'Selected pattern'                             => 'Padrão selecionado',
			'Selected request'                             => 'Solicitação selecionada',
			'We reply within 24 hours on business days. Your details are used only to answer this inquiry — we do not share them.' => 'Respondemos em até 24 horas em dias úteis. Seus dados são usados apenas para responder a esta consulta — não os compartilhamos.',
			'The WhatsApp button opens a chat pre-filled with your details and this pattern. Choosing WhatsApp does not submit this form.' => 'O botão do WhatsApp abre uma conversa preenchida com seus dados e este padrão. Escolher o WhatsApp não envia este formulário.',
			'The WhatsApp button opens a chat pre-filled with your details. Choosing WhatsApp does not submit this form.' => 'O botão do WhatsApp abre uma conversa preenchida com seus dados. Escolher o WhatsApp não envia este formulário.',
			'Send inquiry'                                 => 'Enviar consulta',
			'Send via WhatsApp instead'                    => 'Enviar pelo WhatsApp',
			'WhatsApp'                                     => 'WhatsApp',
			'Language'                                     => 'Idioma',
			// Validation errors.
			'Please enter your name.'                      => 'Por favor, insira seu nome.',
			'Please enter a valid email address.'          => 'Por favor, insira um endereço de e-mail válido.',
			'Please choose your country.'                  => 'Por favor, escolha seu país.',
			'Please choose the project scope.'             => 'Por favor, escolha o escopo do projeto.',
			'Please choose an inquiry route.'              => 'Por favor, escolha o tipo de aplicação.',
			'Please choose a quantity range.'              => 'Por favor, escolha uma faixa de quantidade.',
			'Please add a short message.'                  => 'Por favor, adicione uma mensagem curta.',
			'Please confirm this inquiry is for an architectural or commercial project.' => 'Por favor, confirme que esta consulta é para um projeto arquitetônico ou comercial.',
			'Please complete this field.'                  => 'Por favor, preencha este campo.',
			// Notices.
			'You\'re sending inquiries too quickly. Please wait a few minutes and try again.' => 'Você está enviando consultas muito rápido. Aguarde alguns minutos e tente novamente.',
			'Your form session expired. Please reload the page and try again.' => 'A sessão do formulário expirou. Recarregue a página e tente novamente.',
			'Verification failed. Please refresh the page and try again.' => 'Falha na verificação. Atualize a página e tente novamente.',
			'We could not accept this submission. If this keeps happening, reach us on WhatsApp or by email.' => 'Não foi possível aceitar este envio. Se isso continuar acontecendo, fale conosco pelo WhatsApp ou por e-mail.',
			'Your inquiry could not be saved right now. Please try again, or reach us on WhatsApp or by email.' => 'Não foi possível salvar sua consulta no momento. Tente novamente ou entre em contato pelo WhatsApp ou por e-mail.',
			'Please complete the highlighted fields.'      => 'Por favor, preencha os campos destacados.',
			'Verification expired. Please try again.'      => 'A verificação expirou. Tente novamente.',
			'Verification could not run. Please refresh the page and try again.' => 'Não foi possível executar a verificação. Atualize a página e tente novamente.',
			// JS UI & WhatsApp greetings.
			'Request a quote · %s'                         => 'Solicitar orçamento · %s',
			'Request a quote'                              => 'Solicitar orçamento',
			'Hi, I\'m %s%s.'                               => 'Olá, sou %s%s.',
			'I\'d like a quote for this pattern:'          => 'Gostaria de um orçamento para este padrão:',
			'Inquiry: '                                    => 'Consulta: ',
			'Hi Metal Divider, I\'d like to discuss a stainless steel screen project.' => 'Olá Metal Divider, gostaria de falar sobre um projeto de divisórias em aço inoxidável.',
			'Hi, I\'m interested in a custom metal divider screen project.' => 'Olá Metal Divider, gostaria de solicitar um orçamento para um projeto personalizado de painéis metálicos em aço inoxidável.',
			'Hi Metal Divider, I\'d like a quote for the %s pattern.' => 'Olá Metal Divider, gostaria de solicitar um orçamento personalizado para o padrão %s.',
			// Quote intents.
			'CAD & Shop Drawing Review'                    => 'Revisão de desenhos CAD e executivos',
			'Custom Pattern Brief'                         => 'Briefing de padrão sob medida',
			'Quote Similar Partition Project Spec'         => 'Orçar especificação similar de divisórias',
			'Quote Similar Project Specification'          => 'Orçar especificação de projeto similar',
			'Quote Similar Spec — Coastal Privacy Screens' => 'Orçar especificação similar — Painéis de privacidade litorânea',
			'Quote Similar Spec — Commercial Screening'    => 'Orçar especificação similar — Painéis comerciais',
			'Quote Similar Spec — Facade Screens'          => 'Orçar especificação similar — Painéis de fachada',
			'Quote Similar Spec — Hospitality Feature Wall' => 'Orçar especificação similar — Parede decorativa hoteleira',
			'Quote Similar Spec — Hotel Lobby Screens'     => 'Orçar especificação similar — Painéis para lobby de hotel',
			'Quote Similar Spec — Office Dividers'         => 'Orçar especificação similar — Divisórias corporativas',
			'Quote Similar Spec — PVD Finish Batch'        => 'Orçar especificação similar — Lote de acabamento PVD',
			'Quote Similar Spec — Residential Divider'     => 'Orçar especificação similar — Divisória residencial',
			'Request Physical SS 304/316 Finish Samples'   => 'Solicitar amostras físicas de acabamento em aço inox 304 / 316',
		),
		'de'    => array(
			// Field labels & placeholders.
			'Name'                                         => 'Name',
			'Jane Chan'                                    => 'Anna Schmidt',
			'Email'                                        => 'E-Mail',
			'Phone / WhatsApp (optional)'                  => 'Telefon / WhatsApp (optional)',
			'Message (optional — the pattern is already attached)' => 'Nachricht (optional — das Muster ist bereits beigefügt)',
			'Message'                                      => 'Nachricht',
			'Dimensions, panel count, indoor or outdoor application, finish preference...' => 'Abmessungen, Paneelanzahl, Innen- oder Außenbereich, Oberflächenausführung...',
			'Quantities, dimensions, timeline, finish preferences — anything you already have. Have a drawing, DXF, DWG or PDF? Send it in reply to the confirmation email and it reaches the same engineer.' => 'Mengen, Abmessungen, Zeitplan, Oberflächenwünsche – alles, was Sie bereits haben. Haben Sie eine Zeichnung, DXF, DWG oder PDF? Antworten Sie einfach auf die Bestätigungs-E-Mail, und Ihre Unterlagen erreichen direkt den zuständigen Ingenieur.',
			'Project details (company, country, scope, quantity — optional)' => 'Projektdetails (Firma, Land, Umfang, Menge — optional)',
			'Company (optional)'                           => 'Firma (optional)',
			'Chan Design Studio'                           => 'Architekturbüro',
			'Country (optional)'                           => 'Land (optional)',
			'Select country (optional)'                    => 'Land auswählen (optional)',
			'Project scope (optional)'                     => 'Projektumfang (optional)',
			'Select project scope (optional)'              => 'Projektumfang auswählen (optional)',
			'Inquiry route (optional)'                     => 'Anfrageart (optional)',
			'Select inquiry route (optional)'              => 'Anfrageart auswählen (optional)',
			'Estimated quantity (optional)'                => 'Geschätzte Menge (optional)',
			'Select quantity range (optional)'             => 'Mengenbereich auswählen (optional)',
			'I confirm this inquiry is for an architectural or commercial project. (optional)' => 'Ich bestätige, dass diese Anfrage für ein architektonisches oder gewerbliches Projekt bestimmt ist. (optional)',
			'Leave this field empty'                       => 'Dieses Feld bitte leer lassen',
			'This pattern is attached to your inquiry.'    => 'Dieses Muster ist Ihrer Anfrage beigefügt.',
			'Selected pattern'                             => 'Ausgewähltes Muster',
			'Selected request'                             => 'Ausgewählte Anfrage',
			'We reply within 24 hours on business days. Your details are used only to answer this inquiry — we do not share them.' => 'Wir antworten an Werktagen innerhalb von 24 Stunden. Ihre Angaben werden ausschließlich zur Beantwortung dieser Anfrage verwendet und nicht weitergegeben.',
			'The WhatsApp button opens a chat pre-filled with your details and this pattern. Choosing WhatsApp does not submit this form.' => 'Die WhatsApp-Schaltfläche öffnet einen Chat mit Ihren Daten und diesem Muster. Die Auswahl von WhatsApp übermittelt dieses Formular nicht.',
			'The WhatsApp button opens a chat pre-filled with your details. Choosing WhatsApp does not submit this form.' => 'Die WhatsApp-Schaltfläche öffnet einen Chat mit Ihren Daten. Die Auswahl von WhatsApp übermittelt dieses Formular nicht.',
			'Send inquiry'                                 => 'Anfrage senden',
			'Send via WhatsApp instead'                    => 'Stattdessen über WhatsApp senden',
			'WhatsApp'                                     => 'WhatsApp',
			'Language'                                     => 'Sprache',
			// Validation errors.
			'Please enter your name.'                      => 'Bitte geben Sie Ihren Namen ein.',
			'Please enter a valid email address.'          => 'Bitte geben Sie eine gültige E-Mail-Adresse ein.',
			'Please choose your country.'                  => 'Bitte wählen Sie Ihr Land aus.',
			'Please choose the project scope.'             => 'Bitte wählen Sie den Projektumfang aus.',
			'Please choose an inquiry route.'              => 'Bitte wählen Sie eine Anfrageart aus.',
			'Please choose a quantity range.'              => 'Bitte wählen Sie einen Mengenbereich aus.',
			'Please add a short message.'                  => 'Bitte fügen Sie eine kurze Nachricht hinzu.',
			'Please confirm this inquiry is for an architectural or commercial project.' => 'Bitte bestätigen Sie, dass diese Anfrage für ein architektonisches oder gewerbliches Projekt bestimmt ist.',
			'Please complete this field.'                  => 'Bitte füllen Sie dieses Feld aus.',
			// Notices.
			'You\'re sending inquiries too quickly. Please wait a few minutes and try again.' => 'Sie senden Anfragen zu schnell. Bitte warten Sie einige Minuten und versuchen Sie es erneut.',
			'Your form session expired. Please reload the page and try again.' => 'Ihre Formularsitzung ist abgelaufen. Bitte laden Sie die Seite neu und versuchen Sie es erneut.',
			'Verification failed. Please refresh the page and try again.' => 'Verifizierung fehlgeschlagen. Bitte aktualisieren Sie die Seite und versuchen Sie es erneut.',
			'We could not accept this submission. If this keeps happening, reach us on WhatsApp or by email.' => 'Wir konnten diese Übermittlung nicht annehmen. Sollte dies weiterhin auftreten, erreichen Sie uns per WhatsApp oder E-Mail.',
			'Your inquiry could not be saved right now. Please try again, or reach us on WhatsApp or by email.' => 'Ihre Anfrage konnte derzeit nicht gespeichert werden. Bitte versuchen Sie es erneut oder kontaktieren Sie uns per WhatsApp oder E-Mail.',
			'Please complete the highlighted fields.'      => 'Bitte füllen Sie die markierten Felder aus.',
			'Verification expired. Please try again.'      => 'Die Verifizierung ist abgelaufen. Bitte versuchen Sie es erneut.',
			'Verification could not run. Please refresh the page and try again.' => 'Die Verifizierung konnte nicht ausgeführt werden. Bitte aktualisieren Sie die Seite und versuchen Sie es erneut.',
			// JS UI & WhatsApp greetings.
			'Request a quote · %s'                         => 'Angebot anfordern · %s',
			'Request a quote'                              => 'Angebot anfordern',
			'Hi, I\'m %s%s.'                               => 'Hallo, ich bin %s%s.',
			'I\'d like a quote for this pattern:'          => 'Ich möchte ein Angebot für dieses Muster anfragen:',
			'Inquiry: '                                    => 'Anfrage: ',
			'Hi Metal Divider, I\'d like to discuss a stainless steel screen project.' => 'Hallo Metal Divider, ich möchte gerne ein Projekt für Edelstahl-Trennwände besprechen.',
			'Hi, I\'m interested in a custom metal divider screen project.' => 'Hallo Metal Divider, ich interessiere mich für ein individuelles Projekt mit Edelstahl-Metalltrennwänden und bitte um ein Angebot.',
			'Hi Metal Divider, I\'d like a quote for the %s pattern.' => 'Hallo Metal Divider, ich möchte gerne ein individuelles Angebot für das Muster %s anfragen.',
			// Quote intents.
			'CAD & Shop Drawing Review'                    => 'Prüfung von CAD- und Werkzeichnungen',
			'Custom Pattern Brief'                         => 'Anfrage für individuelles Sondermuster',
			'Quote Similar Partition Project Spec'         => 'Ähnliche Raumteiler-Spezifikation anfragen',
			'Quote Similar Project Specification'          => 'Ähnliche Projektspezifikation anfragen',
			'Quote Similar Spec — Coastal Privacy Screens' => 'Ähnliche Spezifikation — Sichtschutz für Küstenstandorte',
			'Quote Similar Spec — Commercial Screening'    => 'Ähnliche Spezifikation — Gewerbliche Sichtschutzelemente',
			'Quote Similar Spec — Facade Screens'          => 'Ähnliche Spezifikation — Fassadenpaneele',
			'Quote Similar Spec — Hospitality Feature Wall' => 'Ähnliche Spezifikation — Akzentwand für Gastronomie & Hotellerie',
			'Quote Similar Spec — Hotel Lobby Screens'     => 'Ähnliche Spezifikation — Trennwände für Hotellobbys',
			'Quote Similar Spec — Office Dividers'         => 'Ähnliche Spezifikation — Büro-Raumteiler',
			'Quote Similar Spec — PVD Finish Batch'        => 'Ähnliche Spezifikation — PVD-Oberflächencharge',
			'Quote Similar Spec — Residential Divider'     => 'Ähnliche Spezifikation — Wohnraumteiler',
			'Request Physical SS 304/316 Finish Samples'   => 'Physische Oberflächenmuster aus Edelstahl 304 / 316 anfordern',
		),
		'fr'    => array(
			// Field labels & placeholders.
			'Name'                                         => 'Nom',
			'Jane Chan'                                    => 'Marie Dupont',
			'Email'                                        => 'E-mail',
			'Phone / WhatsApp (optional)'                  => 'Téléphone / WhatsApp (facultatif)',
			'Message (optional — the pattern is already attached)' => 'Message (facultatif — le motif est déjà joint)',
			'Message'                                      => 'Message',
			'Dimensions, panel count, indoor or outdoor application, finish preference...' => 'Dimensions, nombre de panneaux, utilisation intérieure ou extérieure, finition...',
			'Quantities, dimensions, timeline, finish preferences — anything you already have. Have a drawing, DXF, DWG or PDF? Send it in reply to the confirmation email and it reaches the same engineer.' => 'Quantités, dimensions, calendrier, finitions souhaitées : tout ce dont vous disposez. Vous avez un plan, un fichier DXF, DWG ou PDF ? Répondez directement à l\'e-mail de confirmation et il parviendra au même ingénieur.',
			'Project details (company, country, scope, quantity — optional)' => 'Détails du projet (entreprise, pays, envergure, quantité — facultatif)',
			'Company (optional)'                           => 'Entreprise (facultatif)',
			'Chan Design Studio'                           => 'Cabinet d\'architecture',
			'Country (optional)'                           => 'Pays (facultatif)',
			'Select country (optional)'                    => 'Sélectionner le pays (facultatif)',
			'Project scope (optional)'                     => 'Envergure du projet (facultatif)',
			'Select project scope (optional)'              => 'Sélectionner l\'envergure du projet (facultatif)',
			'Inquiry route (optional)'                     => 'Type de demande (facultatif)',
			'Select inquiry route (optional)'              => 'Sélectionner le type de demande (facultatif)',
			'Estimated quantity (optional)'                => 'Quantité estimée (facultatif)',
			'Select quantity range (optional)'             => 'Sélectionner une plage de quantité (facultatif)',
			'I confirm this inquiry is for an architectural or commercial project. (optional)' => 'Je confirme que cette demande concerne un projet architectural ou commercial. (facultatif)',
			'Leave this field empty'                       => 'Laissez ce champ vide',
			'This pattern is attached to your inquiry.'    => 'Ce motif est joint à votre demande.',
			'Selected pattern'                             => 'Motif sélectionné',
			'Selected request'                             => 'Demande sélectionnée',
			'We reply within 24 hours on business days. Your details are used only to answer this inquiry — we do not share them.' => 'Nous répondons sous 24 heures les jours ouvrés. Vos coordonnées sont utilisées uniquement pour traiter cette demande — nous ne les partageons pas.',
			'The WhatsApp button opens a chat pre-filled with your details and this pattern. Choosing WhatsApp does not submit this form.' => 'Le bouton WhatsApp ouvre une discussion préremplie avec vos coordonnées et ce motif. Choisir WhatsApp ne soumet pas ce formulaire.',
			'The WhatsApp button opens a chat pre-filled with your details. Choosing WhatsApp does not submit this form.' => 'Le bouton WhatsApp ouvre une discussion préremplie avec vos coordonnées. Choisir WhatsApp ne soumet pas ce formulaire.',
			'Send inquiry'                                 => 'Envoyer la demande',
			'Send via WhatsApp instead'                    => 'Envoyer plutôt via WhatsApp',
			'WhatsApp'                                     => 'WhatsApp',
			'Language'                                     => 'Langue',
			// Validation errors.
			'Please enter your name.'                      => 'Veuillez renseigner votre nom.',
			'Please enter a valid email address.'          => 'Veuillez renseigner une adresse e-mail valide.',
			'Please choose your country.'                  => 'Veuillez sélectionner votre pays.',
			'Please choose the project scope.'             => 'Veuillez sélectionner l\'envergure du projet.',
			'Please choose an inquiry route.'              => 'Veuillez sélectionner un type de demande.',
			'Please choose a quantity range.'              => 'Veuillez sélectionner une plage de quantité.',
			'Please add a short message.'                  => 'Veuillez ajouter un court message.',
			'Please confirm this inquiry is for an architectural or commercial project.' => 'Veuillez confirmer que cette demande concerne un projet architectural ou commercial.',
			'Please complete this field.'                  => 'Veuillez remplir ce champ.',
			// Notices.
			'You\'re sending inquiries too quickly. Please wait a few minutes and try again.' => 'Vous envoyez des demandes trop rapidement. Veuillez patienter quelques minutes et réessayer.',
			'Your form session expired. Please reload the page and try again.' => 'Votre session de formulaire a expiré. Veuillez recharger la page et réessayer.',
			'Verification failed. Please refresh the page and try again.' => 'Échec de la vérification. Veuillez actualiser la page et réessayer.',
			'We could not accept this submission. If this keeps happening, reach us on WhatsApp or by email.' => 'Nous n\'avons pas pu accepter cet envoi. Si cela persiste, contactez-nous par WhatsApp ou par e-mail.',
			'Your inquiry could not be saved right now. Please try again, or reach us on WhatsApp or by email.' => 'Votre demande n\'a pas pu être enregistrée pour le moment. Veuillez réessayer ou nous contacter par WhatsApp ou par e-mail.',
			'Please complete the highlighted fields.'      => 'Veuillez remplir les champs mis en surbrillance.',
			'Verification expired. Please try again.'      => 'La vérification a expiré. Veuillez réessayer.',
			'Verification could not run. Please refresh the page and try again.' => 'Impossible d\'exécuter la vérification. Veuillez actualiser la page et réessayer.',
			// JS UI & WhatsApp greetings.
			'Request a quote · %s'                         => 'Demander un devis · %s',
			'Request a quote'                              => 'Demander un devis',
			'Hi, I\'m %s%s.'                               => 'Bonjour, je suis %s%s.',
			'I\'d like a quote for this pattern:'          => 'Je souhaite recevoir un devis pour ce motif :',
			'Inquiry: '                                    => 'Demande : ',
			'Hi Metal Divider, I\'d like to discuss a stainless steel screen project.' => 'Bonjour Metal Divider, je souhaiterais échanger sur un projet de cloisons en acier inoxydable.',
			'Hi, I\'m interested in a custom metal divider screen project.' => 'Bonjour Metal Divider, je souhaite obtenir un devis pour un projet sur mesure de panneaux métalliques en acier inoxydable.',
			'Hi Metal Divider, I\'d like a quote for the %s pattern.' => 'Bonjour Metal Divider, je souhaiterais recevoir un devis sur mesure pour le motif %s.',
			// Quote intents.
			'CAD & Shop Drawing Review'                    => 'Étude de plans CAO et d\'exécution',
			'Custom Pattern Brief'                         => 'Cahier des charges de motif sur mesure',
			'Quote Similar Partition Project Spec'         => 'Chiffrer une spécification de cloison similaire',
			'Quote Similar Project Specification'          => 'Chiffrer une spécification de projet similaire',
			'Quote Similar Spec — Coastal Privacy Screens' => 'Spécification similaire — Brise-vue pour zone côtière',
			'Quote Similar Spec — Commercial Screening'    => 'Spécification similaire — Panneaux commerciaux',
			'Quote Similar Spec — Facade Screens'          => 'Spécification similaire — Panneaux de façade',
			'Quote Similar Spec — Hospitality Feature Wall' => 'Spécification similaire — Mur décoratif hôtelier',
			'Quote Similar Spec — Hotel Lobby Screens'     => 'Spécification similaire — Cloisons de hall d\'hôtel',
			'Quote Similar Spec — Office Dividers'         => 'Spécification similaire — Cloisons de bureaux',
			'Quote Similar Spec — PVD Finish Batch'        => 'Spécification similaire — Lot de finition PVD',
			'Quote Similar Spec — Residential Divider'     => 'Spécification similaire — Cloison résidentielle',
			'Request Physical SS 304/316 Finish Samples'   => 'Demander des échantillons physiques de finitions inox 304 / 316',
		),
		'ar'    => array(
			// Field labels & placeholders.
			'Name'                                         => 'الاسم',
			'Jane Chan'                                    => 'أحمد علي',
			'Email'                                        => 'البريد الإلكتروني',
			'Phone / WhatsApp (optional)'                  => 'الهاتف / واتساب (اختياري)',
			'Message (optional — the pattern is already attached)' => 'رسالة (اختياري — النمط مرفق بالفعل)',
			'Message'                                      => 'رسالة',
			'Dimensions, panel count, indoor or outdoor application, finish preference...' => 'الأبعاد، عدد الألواح، الاستخدام الداخلي أو الخارجي، اللمسة النهائية...',
			'Quantities, dimensions, timeline, finish preferences — anything you already have. Have a drawing, DXF, DWG or PDF? Send it in reply to the confirmation email and it reaches the same engineer.' => 'الكميات، الأبعاد، الجدول الزمني، تفضيلات المعالجة النهائية — أي تفاصيل متوفرة لديك. هل لديك مخطط أو ملف DXF أو DWG أو PDF؟ يمكنك إرساله بالرد على رسالة التأكيد وسيصل إلى المهندس المختص.',
			'Project details (company, country, scope, quantity — optional)' => 'تفاصيل المشروع (الشركة، البلد، نطاق المشروع، الكمية — اختياري)',
			'Company (optional)'                           => 'الشركة (اختياري)',
			'Chan Design Studio'                           => 'استوديو التصميم',
			'Country (optional)'                           => 'البلد (اختياري)',
			'Select country (optional)'                    => 'اختر البلد (اختياري)',
			'Project scope (optional)'                     => 'نطاق المشروع (اختياري)',
			'Select project scope (optional)'              => 'اختر نطاق المشروع (اختياري)',
			'Inquiry route (optional)'                     => 'مسار الاستفسار (اختياري)',
			'Select inquiry route (optional)'              => 'اختر مسار الاستفسار (اختياري)',
			'Estimated quantity (optional)'                => 'الكمية المقدرة (اختياري)',
			'Select quantity range (optional)'             => 'اختر نطاق الكمية (اختياري)',
			'I confirm this inquiry is for an architectural or commercial project. (optional)' => 'أؤكد أن هذا الاستفسار خاص بمشروع معماري أو تجاري. (اختياري)',
			'Leave this field empty'                       => 'اترك هذا الحقل فارغاً',
			'This pattern is attached to your inquiry.'    => 'هذا النمط مرفق باستفسارك.',
			'Selected pattern'                             => 'النمط المختار',
			'Selected request'                             => 'الطلب المختار',
			'We reply within 24 hours on business days. Your details are used only to answer this inquiry — we do not share them.' => 'نرد خلال 24 ساعة في أيام العمل. تُستخدم بياناتك فقط للرد على هذا الاستفسار — ولا نشاركها مع أي طرف آخر.',
			'The WhatsApp button opens a chat pre-filled with your details and this pattern. Choosing WhatsApp does not submit this form.' => 'يفتح زر واتساب محادثة معبأة مسبقاً ببياناتك وهذا النمط. اختيار واتساب لا يرسل هذا النموذج.',
			'The WhatsApp button opens a chat pre-filled with your details. Choosing WhatsApp does not submit this form.' => 'يفتح زر واتساب محادثة معبأة مسبقاً ببياناتك. اختيار واتساب لا يرسل هذا النموذج.',
			'Send inquiry'                                 => 'إرسال الاستفسار',
			'Send via WhatsApp instead'                    => 'الإرسال عبر واتساب بدلاً من ذلك',
			'WhatsApp'                                     => 'واتساب',
			'Language'                                     => 'اللغة',
			// Validation errors.
			'Please enter your name.'                      => 'يرجى إدخال اسمك.',
			'Please enter a valid email address.'          => 'يرجى إدخال عنوان بريد إلكتروني صالح.',
			'Please choose your country.'                  => 'يرجى اختيار بلدك.',
			'Please choose the project scope.'             => 'يرجى اختيار نطاق المشروع.',
			'Please choose an inquiry route.'              => 'يرجى اختيار مسار الاستفسار.',
			'Please choose a quantity range.'              => 'يرجى اختيار نطاق الكمية.',
			'Please add a short message.'                  => 'يرجى إضافة رسالة قصيرة.',
			'Please confirm this inquiry is for an architectural or commercial project.' => 'يرجى تأكيد أن هذا الاستفسار مخصص لمشروع معماري أو تجاري.',
			'Please complete this field.'                  => 'يرجى إكمال هذا الحقل.',
			// Notices.
			'You\'re sending inquiries too quickly. Please wait a few minutes and try again.' => 'أنت ترسل استفسارات بسرعة كبيرة. يرجى الانتظار بضع دقائق ثم المحاولة مرة أخرى.',
			'Your form session expired. Please reload the page and try again.' => 'انتهت صلاحية جلسة النموذج. يرجى إعادة تحميل الصفحة والمحاولة مرة أخرى.',
			'Verification failed. Please refresh the page and try again.' => 'فشل التحقق. يرجى تحديث الصفحة والمحاولة مرة أخرى.',
			'We could not accept this submission. If this keeps happening, reach us on WhatsApp or by email.' => 'لم نتمكن من قبول هذا الإرسال. إذا استمر هذا الأمر، يرجى التواصل معنا عبر واتساب أو البريد الإلكتروني.',
			'Your inquiry could not be saved right now. Please try again, or reach us on WhatsApp or by email.' => 'تعذر حفظ استفسارك في الوقت الحالي. يرجى المحاولة مرة أخرى، أو التواصل معنا عبر واتساب أو البريد الإلكتروني.',
			'Please complete the highlighted fields.'      => 'يرجى إكمال الحقول المحددة.',
			'Verification expired. Please try again.'      => 'انتهت صلاحية التحقق. يرجى المحاولة مرة أخرى.',
			'Verification could not run. Please refresh the page and try again.' => 'تعذر تشغيل التحقق. يرجى تحديث الصفحة والمحاولة مرة أخرى.',
			// JS UI & WhatsApp greetings.
			'Request a quote · %s'                         => 'طلب عرض أسعار · %s',
			'Request a quote'                              => 'طلب عرض أسعار',
			'Hi, I\'m %s%s.'                               => 'مرحباً، أنا %s%s.',
			'I\'d like a quote for this pattern:'          => 'أود الحصول على عرض أسعار لهذا النمط:',
			'Inquiry: '                                    => 'الاستفسار: ',
			'Hi Metal Divider, I\'d like to discuss a stainless steel screen project.' => 'مرحباً Metal Divider، أود مناقشة مشروع حواجز من الفولاذ المقاوم للصدأ.',
			'Hi, I\'m interested in a custom metal divider screen project.' => 'مرحباً Metal Divider، أود الاستفسار عن عرض أسعار لمشروع فواصل وشاشات معدنية مخصصة من الفولاذ المقاوم للصدأ.',
			'Hi Metal Divider, I\'d like a quote for the %s pattern.' => 'مرحباً Metal Divider، أود طلب عرض أسعار مخصص لنمط %s.',
			// Quote intents.
			'CAD & Shop Drawing Review'                    => 'مراجعة مخططات CAD والرسومات التنفيذية',
			'Custom Pattern Brief'                         => 'ملخص تصميم نمط مخصص',
			'Quote Similar Partition Project Spec'         => 'طلب عرض أسعار لمواصفات قواطع مماثلة',
			'Quote Similar Project Specification'          => 'طلب عرض أسعار لمواصفات مشروع مماثل',
			'Quote Similar Spec — Coastal Privacy Screens' => 'مواصفات مماثلة — شاشات خصوصية للمناطق الساحلية',
			'Quote Similar Spec — Commercial Screening'    => 'مواصفات مماثلة — فواصل للمشاريع التجارية',
			'Quote Similar Spec — Facade Screens'          => 'مواصفات مماثلة — شاشات الواجهات المعمارية',
			'Quote Similar Spec — Hospitality Feature Wall' => 'مواصفات مماثلة — جدار مميز للضيافة والفنادق',
			'Quote Similar Spec — Hotel Lobby Screens'     => 'مواصفات مماثلة — فواصل ردهات الفنادق',
			'Quote Similar Spec — Office Dividers'         => 'مواصفات مماثلة — قواطع المكاتب',
			'Quote Similar Spec — PVD Finish Batch'        => 'مواصفات مماثلة — دفعة تشطيب PVD',
			'Quote Similar Spec — Residential Divider'     => 'مواصفات مماثلة — فاصل سكني',
			'Request Physical SS 304/316 Finish Samples'   => 'طلب عينات فعلية لتشطيبات الفولاذ المقاوم للصدأ 304 / 316',
		),
		'id'    => array(
			// Field labels & placeholders.
			'Name'                                         => 'Nama',
			'Jane Chan'                                    => 'Budi Santoso',
			'Email'                                        => 'Email',
			'Phone / WhatsApp (optional)'                  => 'Telepon / WhatsApp (opsional)',
			'Message (optional — the pattern is already attached)' => 'Pesan (opsional — pola sudah terlampir)',
			'Message'                                      => 'Pesan',
			'Dimensions, panel count, indoor or outdoor application, finish preference...' => 'Dimensi, jumlah panel, aplikasi dalam atau luar ruangan, pilihan finishing...',
			'Quantities, dimensions, timeline, finish preferences — anything you already have. Have a drawing, DXF, DWG or PDF? Send it in reply to the confirmation email and it reaches the same engineer.' => 'Jumlah, dimensi, jadwal waktu, preferensi finishing — semua detail yang Anda miliki. Memiliki gambar teknik, DXF, DWG, atau PDF? Kirimkan sebagai balasan ke email konfirmasi dan akan langsung sampai ke teknisi terkait.',
			'Project details (company, country, scope, quantity — optional)' => 'Detail proyek (perusahaan, negara, cakupan, jumlah — opsional)',
			'Company (optional)'                           => 'Perusahaan (opsional)',
			'Chan Design Studio'                           => 'Studio Desain',
			'Country (optional)'                           => 'Negara (opsional)',
			'Select country (optional)'                    => 'Pilih negara (opsional)',
			'Project scope (optional)'                     => 'Cakupan proyek (opsional)',
			'Select project scope (optional)'              => 'Pilih cakupan proyek (opsional)',
			'Inquiry route (optional)'                     => 'Kategori aplikasi (opsional)',
			'Select inquiry route (optional)'              => 'Pilih kategori aplikasi (opsional)',
			'Estimated quantity (optional)'                => 'Perkiraan jumlah (opsional)',
			'Select quantity range (optional)'             => 'Pilih rentang jumlah (opsional)',
			'I confirm this inquiry is for an architectural or commercial project. (optional)' => 'Saya mengonfirmasi bahwa permintaan ini untuk proyek arsitektur atau komersial. (opsional)',
			'Leave this field empty'                       => 'Biarkan bidang ini kosong',
			'This pattern is attached to your inquiry.'    => 'Pola ini terlampir pada permintaan Anda.',
			'Selected pattern'                             => 'Pola yang dipilih',
			'Selected request'                             => 'Permintaan yang dipilih',
			'We reply within 24 hours on business days. Your details are used only to answer this inquiry — we do not share them.' => 'Kami membalas dalam 24 jam pada hari kerja. Rincian Anda hanya digunakan untuk menjawab permintaan ini — kami tidak membagikannya.',
			'The WhatsApp button opens a chat pre-filled with your details and this pattern. Choosing WhatsApp does not submit this form.' => 'Tombol WhatsApp membuka obrolan yang sudah terisi dengan rincian Anda dan pola ini. Memilih WhatsApp tidak mengirimkan formulir ini.',
			'The WhatsApp button opens a chat pre-filled with your details. Choosing WhatsApp does not submit this form.' => 'Tombol WhatsApp membuka obrolan yang sudah terisi dengan rincian Anda. Memilih WhatsApp tidak mengirimkan formulir ini.',
			'Send inquiry'                                 => 'Kirim permintaan',
			'Send via WhatsApp instead'                    => 'Kirim melalui WhatsApp',
			'WhatsApp'                                     => 'WhatsApp',
			'Language'                                     => 'Bahasa',
			// Validation errors.
			'Please enter your name.'                      => 'Silakan masukkan nama Anda.',
			'Please enter a valid email address.'          => 'Silakan masukkan alamat email yang valid.',
			'Please choose your country.'                  => 'Silakan pilih negara Anda.',
			'Please choose the project scope.'             => 'Silakan pilih cakupan proyek.',
			'Please choose an inquiry route.'              => 'Silakan pilih kategori aplikasi.',
			'Please choose a quantity range.'              => 'Silakan pilih rentang jumlah.',
			'Please add a short message.'                  => 'Silakan tambahkan pesan singkat.',
			'Please confirm this inquiry is for an architectural or commercial project.' => 'Silakan konfirmasi bahwa permintaan ini untuk proyek arsitektur atau komersial.',
			'Please complete this field.'                  => 'Silakan lengkapi bidang ini.',
			// Notices.
			'You\'re sending inquiries too quickly. Please wait a few minutes and try again.' => 'Anda mengirim permintaan terlalu cepat. Harap tunggu beberapa menit dan coba lagi.',
			'Your form session expired. Please reload the page and try again.' => 'Sesi formulir Anda telah berakhir. Silakan muat ulang halaman dan coba lagi.',
			'Verification failed. Please refresh the page and try again.' => 'Verifikasi gagal. Silakan segarkan halaman dan coba lagi.',
			'We could not accept this submission. If this keeps happening, reach us on WhatsApp or by email.' => 'Kami tidak dapat menerima pengiriman ini. Jika hal ini terus berlanjut, hubungi kami melalui WhatsApp atau email.',
			'Your inquiry could not be saved right now. Please try again, or reach us on WhatsApp or by email.' => 'Permintaan Anda tidak dapat disimpan saat ini. Silakan coba lagi, atau hubungi kami melalui WhatsApp atau email.',
			'Please complete the highlighted fields.'      => 'Silakan lengkapi bidang yang disorot.',
			'Verification expired. Please try again.'      => 'Verifikasi telah kedaluwarsa. Silakan coba lagi.',
			'Verification could not run. Please refresh the page and try again.' => 'Verifikasi tidak dapat berjalan. Silakan segarkan halaman dan coba lagi.',
			// JS UI & WhatsApp greetings.
			'Request a quote · %s'                         => 'Minta penawaran · %s',
			'Request a quote'                              => 'Minta penawaran',
			'Hi, I\'m %s%s.'                               => 'Halo, saya %s%s.',
			'I\'d like a quote for this pattern:'          => 'Saya ingin meminta penawaran untuk pola ini:',
			'Inquiry: '                                    => 'Permintaan: ',
			'Hi Metal Divider, I\'d like to discuss a stainless steel screen project.' => 'Halo Metal Divider, saya ingin mendiskusikan proyek partisi stainless steel.',
			'Hi, I\'m interested in a custom metal divider screen project.' => 'Halo Metal Divider, saya tertarik untuk meminta penawaran proyek partisi logam stainless steel kustom.',
			'Hi Metal Divider, I\'d like a quote for the %s pattern.' => 'Halo Metal Divider, saya ingin meminta penawaran kustom untuk pola %s.',
			// Quote intents.
			'CAD & Shop Drawing Review'                    => 'Tinjauan Gambar CAD & Gambar Kerja',
			'Custom Pattern Brief'                         => 'Rincian Pola Kustom',
			'Quote Similar Partition Project Spec'         => 'Minta Penawaran Spesifikasi Partisi Serupa',
			'Quote Similar Project Specification'          => 'Minta Penawaran Spesifikasi Proyek Serupa',
			'Quote Similar Spec — Coastal Privacy Screens' => 'Spesifikasi Serupa — Layar Privasi Area Pesisir',
			'Quote Similar Spec — Commercial Screening'    => 'Spesifikasi Serupa — Partisi Area Komersial',
			'Quote Similar Spec — Facade Screens'          => 'Spesifikasi Serupa — Panel Fasad Bangunan',
			'Quote Similar Spec — Hospitality Feature Wall' => 'Spesifikasi Serupa — Dinding Aksen Perhotelan',
			'Quote Similar Spec — Hotel Lobby Screens'     => 'Spesifikasi Serupa — Partisi Lobi Hotel',
			'Quote Similar Spec — Office Dividers'         => 'Spesifikasi Serupa — Partisi Ruang Kantor',
			'Quote Similar Spec — PVD Finish Batch'        => 'Spesifikasi Serupa — Batch Finishing PVD',
			'Quote Similar Spec — Residential Divider'     => 'Spesifikasi Serupa — Partisi Hunian',
			'Request Physical SS 304/316 Finish Samples'   => 'Minta Sampel Fisik Finishing Stainless Steel 304 / 316',
		),
		'vi'    => array(
			// Field labels & placeholders.
			'Name'                                         => 'Họ và tên',
			'Jane Chan'                                    => 'Nguyễn Văn A',
			'Email'                                        => 'Email',
			'Phone / WhatsApp (optional)'                  => 'Điện thoại / WhatsApp (tùy chọn)',
			'Message (optional — the pattern is already attached)' => 'Tin nhắn (tùy chọn — họa tiết đã được đính kèm)',
			'Message'                                      => 'Tin nhắn',
			'Dimensions, panel count, indoor or outdoor application, finish preference...' => 'Kích thước, số lượng tấm, ứng dụng trong nhà hay ngoài trời, hoàn thiện bề mặt...',
			'Quantities, dimensions, timeline, finish preferences — anything you already have. Have a drawing, DXF, DWG or PDF? Send it in reply to the confirmation email and it reaches the same engineer.' => 'Số lượng, kích thước, tiến độ, yêu cầu bề mặt hoàn thiện — bất kỳ thông tin nào bạn có. Bạn có bản vẽ, tệp DXF, DWG hoặc PDF? Hãy gửi bằng cách trả lời email xác nhận, bản vẽ sẽ được chuyển thẳng đến kỹ sư phụ trách.',
			'Project details (company, country, scope, quantity — optional)' => 'Chi tiết dự án (công ty, quốc gia, quy mô, số lượng — tùy chọn)',
			'Company (optional)'                           => 'Công ty (tùy chọn)',
			'Chan Design Studio'                           => 'Công ty thiết kế kiến trúc',
			'Country (optional)'                           => 'Quốc gia (tùy chọn)',
			'Select country (optional)'                    => 'Chọn quốc gia (tùy chọn)',
			'Project scope (optional)'                     => 'Quy mô dự án (tùy chọn)',
			'Select project scope (optional)'              => 'Chọn quy mô dự án (tùy chọn)',
			'Inquiry route (optional)'                     => 'Mục đích sử dụng (tùy chọn)',
			'Select inquiry route (optional)'              => 'Chọn mục đích sử dụng (tùy chọn)',
			'Estimated quantity (optional)'                => 'Số lượng dự kiến (tùy chọn)',
			'Select quantity range (optional)'             => 'Chọn khoảng số lượng (tùy chọn)',
			'I confirm this inquiry is for an architectural or commercial project. (optional)' => 'Tôi xác nhận yêu cầu báo giá này dành cho dự án kiến trúc hoặc thương mại. (tùy chọn)',
			'Leave this field empty'                       => 'Vui lòng để trống trường này',
			'This pattern is attached to your inquiry.'    => 'Họa tiết này được đính kèm trong yêu cầu báo giá của bạn.',
			'Selected pattern'                             => 'Họa tiết đã chọn',
			'Selected request'                             => 'Yêu cầu đã chọn',
			'We reply within 24 hours on business days. Your details are used only to answer this inquiry — we do not share them.' => 'Chúng tôi phản hồi trong vòng 24 giờ vào các ngày làm việc. Thông tin của bạn chỉ được dùng để trả lời yêu cầu này — chúng tôi cam kết bảo mật tuyệt đối.',
			'The WhatsApp button opens a chat pre-filled with your details and this pattern. Choosing WhatsApp does not submit this form.' => 'Nút WhatsApp sẽ mở cuộc trò chuyện điền sẵn thông tin của bạn và họa tiết này. Chọn WhatsApp sẽ không gửi biểu mẫu này.',
			'The WhatsApp button opens a chat pre-filled with your details. Choosing WhatsApp does not submit this form.' => 'Nút WhatsApp sẽ mở cuộc trò chuyện điền sẵn thông tin của bạn. Chọn WhatsApp sẽ không gửi biểu mẫu này.',
			'Send inquiry'                                 => 'Gửi yêu cầu báo giá',
			'Send via WhatsApp instead'                    => 'Gửi qua WhatsApp',
			'WhatsApp'                                     => 'WhatsApp',
			'Language'                                     => 'Ngôn ngữ',
			// Validation errors.
			'Please enter your name.'                      => 'Vui lòng nhập họ và tên của bạn.',
			'Please enter a valid email address.'          => 'Vui lòng nhập địa chỉ email hợp lệ.',
			'Please choose your country.'                  => 'Vui lòng chọn quốc gia của bạn.',
			'Please choose the project scope.'             => 'Vui lòng chọn quy mô dự án.',
			'Please choose an inquiry route.'              => 'Vui lòng chọn mục đích sử dụng.',
			'Please choose a quantity range.'              => 'Vui lòng chọn khoảng số lượng.',
			'Please add a short message.'                  => 'Vui lòng ghi ngắn gọn yêu cầu của bạn.',
			'Please confirm this inquiry is for an architectural or commercial project.' => 'Vui lòng xác nhận yêu cầu báo giá này dành cho dự án kiến trúc hoặc thương mại.',
			'Please complete this field.'                  => 'Vui lòng điền vào trường này.',
			// Notices.
			'You\'re sending inquiries too quickly. Please wait a few minutes and try again.' => 'Bạn đang gửi yêu cầu quá nhanh. Vui lòng đợi vài phút rồi thử lại.',
			'Your form session expired. Please reload the page and try again.' => 'Phiên làm việc đã hết hạn. Vui lòng tải lại trang và thử lại.',
			'Verification failed. Please refresh the page and try again.' => 'Xác minh không thành công. Vui lòng tải lại trang và thử lại.',
			'We could not accept this submission. If this keeps happening, reach us on WhatsApp or by email.' => 'Không thể xử lý yêu cầu này. Nếu sự cố tiếp tục xảy ra, vui lòng liên hệ với chúng tôi qua WhatsApp hoặc email.',
			'Your inquiry could not be saved right now. Please try again, or reach us on WhatsApp or by email.' => 'Chưa thể lưu yêu cầu báo giá của bạn lúc này. Vui lòng thử lại hoặc liên hệ với chúng tôi qua WhatsApp hoặc email.',
			'Please complete the highlighted fields.'      => 'Vui lòng điền đầy đủ các trường được đánh dấu.',
			'Verification expired. Please try again.'      => 'Xác minh đã hết hạn. Vui lòng thử lại.',
			'Verification could not run. Please refresh the page and try again.' => 'Không thể thực hiện xác minh. Vui lòng tải lại trang và thử lại.',
			// JS UI & WhatsApp greetings.
			'Request a quote · %s'                         => 'Yêu cầu báo giá · %s',
			'Request a quote'                              => 'Yêu cầu báo giá',
			'Hi, I\'m %s%s.'                               => 'Xin chào, tôi là %s%s.',
			'I\'d like a quote for this pattern:'          => 'Tôi muốn nhận báo giá cho họa tiết này:',
			'Inquiry: '                                    => 'Yêu cầu: ',
			'Hi Metal Divider, I\'d like to discuss a stainless steel screen project.' => 'Xin chào Metal Divider, tôi muốn trao đổi về dự án vách ngăn inox (thép không gỉ).',
			'Hi, I\'m interested in a custom metal divider screen project.' => 'Xin chào Metal Divider, tôi muốn nhận báo giá cho dự án vách ngăn kim loại thép không gỉ theo yêu cầu.',
			'Hi Metal Divider, I\'d like a quote for the %s pattern.' => 'Xin chào Metal Divider, tôi muốn nhận báo giá theo yêu cầu cho họa tiết %s.',
			// Quote intents.
			'CAD & Shop Drawing Review'                    => 'Đánh giá bản vẽ CAD & bản vẽ thi công',
			'Custom Pattern Brief'                         => 'Yêu cầu thiết kế họa tiết riêng',
			'Quote Similar Partition Project Spec'         => 'Báo giá quy cách dự án vách ngăn tương tự',
			'Quote Similar Project Specification'          => 'Báo giá quy cách dự án tương tự',
			'Quote Similar Spec — Coastal Privacy Screens' => 'Quy cách tương tự — Vách ngăn riêng tư ven biển',
			'Quote Similar Spec — Commercial Screening'    => 'Quy cách tương tự — Vách ngăn không gian thương mại',
			'Quote Similar Spec — Facade Screens'          => 'Quy cách tương tự — Mặt dựng kiến trúc',
			'Quote Similar Spec — Hospitality Feature Wall' => 'Quy cách tương tự — Vách trang trí khách sạn & nhà hàng',
			'Quote Similar Spec — Hotel Lobby Screens'     => 'Quy cách tương tự — Vách ngăn sảnh khách sạn',
			'Quote Similar Spec — Office Dividers'         => 'Quy cách tương tự — Vách ngăn văn phòng',
			'Quote Similar Spec — PVD Finish Batch'        => 'Quy cách tương tự — Lô mạ màu PVD theo yêu cầu',
			'Quote Similar Spec — Residential Divider'     => 'Quy cách tương tự — Vách ngăn nhà ở',
			'Request Physical SS 304/316 Finish Samples'   => 'Yêu cầu mẫu bề mặt thực tế inox 304 / 316',
		),
	);

	/**
	 * Canonical quote intent keys emitted by template CTAs.
	 *
	 * @var list<string>
	 */
	public const INTENT_KEYS = array(
		'CAD & Shop Drawing Review',
		'Custom Pattern Brief',
		'Quote Similar Partition Project Spec',
		'Quote Similar Project Specification',
		'Quote Similar Spec — Coastal Privacy Screens',
		'Quote Similar Spec — Commercial Screening',
		'Quote Similar Spec — Facade Screens',
		'Quote Similar Spec — Hospitality Feature Wall',
		'Quote Similar Spec — Hotel Lobby Screens',
		'Quote Similar Spec — Office Dividers',
		'Quote Similar Spec — PVD Finish Batch',
		'Quote Similar Spec — Residential Divider',
		'Request Physical SS 304/316 Finish Samples',
	);

	/**
	 * Returns localized display labels keyed by canonical English quote intent.
	 *
	 * @param string|null $locale Optional target locale (defaults to current).
	 * @return array<string, string>
	 */
	public static function intent_labels( ?string $locale = null ): array {
		$target = Locale::normalize( $locale ?? Locale::current() );
		if ( 'en' === $target || '' === $target ) {
			return array();
		}

		$out = array();
		foreach ( self::INTENT_KEYS as $key ) {
			$out[ $key ] = self::translate( $key, $target );
		}
		return $out;
	}

	/**
	 * Translates a customer-facing string into the specified or current locale.
	 *
	 * Falls back to the English text if no translation is configured.
	 *
	 * @param string      $text   The original English text.
	 * @param string|null $locale Optional target locale (defaults to current).
	 * @return string
	 */
	public static function translate( string $text, ?string $locale = null ): string {
		$target = Locale::normalize( $locale ?? Locale::current() );
		if ( 'en' === $target || '' === $target ) {
			return $text;
		}

		return self::STRINGS[ $target ][ $text ] ?? $text;
	}

	/**
	 * Translates a select option value into its localized display label.
	 *
	 * Keeps the underlying option value identical to the English constant while
	 * rendering the human-readable translated label for visitors.
	 *
	 * @param string      $value  The underlying English option value.
	 * @param string|null $locale Optional target locale (defaults to current).
	 * @return string
	 */
	public static function option_label( string $value, ?string $locale = null ): string {
		$target = Locale::normalize( $locale ?? Locale::current() );
		if ( 'en' === $target || '' === $target ) {
			return $value;
		}

		return self::OPTIONS[ $target ][ $value ] ?? $value;
	}

	/**
	 * Composes the localized customer confirmation email for non-English locales.
	 *
	 * @param Config               $config The configuration.
	 * @param array<string, mixed> $record The inquiry record values.
	 * @param string               $locale The target canonical locale.
	 * @return array{to: list<string>, subject: string, body: string, headers: list<string>}
	 */
	public static function confirmation_email( Config $config, array $record, string $locale ): array {
		$target  = Locale::normalize( $locale );
		$name    = isset( $record['name'] ) && \is_string( $record['name'] ) ? $record['name'] : '';
		$email   = isset( $record['email'] ) && \is_string( $record['email'] ) ? $record['email'] : '';
		$pattern = self::pattern( $record );

		$subject = self::email_subject( $pattern, $target );
		$lines   = array(
			self::email_greeting( $name, $target ),
			'',
			self::email_thank_you( $pattern, $target ),
			'',
			self::email_promise( $target ),
			'',
			self::email_channels_intro( $target ),
			'- WhatsApp: +852 6473 1687 — https://wa.me/85264731687',
			'- ' . ( 'zh' === $target ? '邮箱' : ( 'es' === $target ? 'Correo' : ( 'de' === $target ? 'E-Mail' : 'Email' ) ) ) . ': info@metal-divider.com',
			'',
			self::email_signoff( $target ),
			self::email_team( $target ),
			'metal-divider.com',
		);

		return array(
			'to'      => array( $email ),
			'subject' => $subject,
			'body'    => \implode( "\n", $lines ),
			'headers' => array(
				'From: MetalDivider <' . $config->from_email() . '>',
				'Reply-To: ' . Emails::REPLY_TO,
				'Content-Type: text/plain; charset=UTF-8',
			),
		);
	}

	/**
	 * Resolves the pattern context from an inquiry record.
	 *
	 * @param array<string, mixed> $record The record values.
	 * @return array{name: string, code: string}|null
	 */
	private static function pattern( array $record ): ?array {
		$name = isset( $record['pattern_name'] ) && \is_string( $record['pattern_name'] ) ? $record['pattern_name'] : '';
		return '' !== $name ? array(
			'name' => $name,
			'code' => (string) ( $record['pattern_code'] ?? '' ),
		) : null;
	}

	/**
	 * Localized email subject line.
	 *
	 * @param array{name: string, code: string}|null $pattern The pattern.
	 * @param string                                 $locale  The canonical locale.
	 * @return string
	 */
	private static function email_subject( ?array $pattern, string $locale ): string {
		if ( null !== $pattern ) {
			return match ( $locale ) {
				'zh'    => '我们已收到您关于图案 ' . $pattern['name'] . ' 的询盘',
				'es'    => 'Hemos recibido su consulta sobre el patrón ' . $pattern['name'],
				'pt-br' => 'Recebemos sua consulta sobre o padrão ' . $pattern['name'],
				'de'    => 'Wir haben Ihre Anfrage zu Muster ' . $pattern['name'] . ' erhalten',
				'fr'    => 'Nous avons bien reçu votre demande concernant le motif ' . $pattern['name'],
				'ar'    => 'لقد استلمنا استفسارك بخصوص نمط ' . $pattern['name'],
				'id'    => 'Kami telah menerima permintaan Anda mengenai pola ' . $pattern['name'],
				'vi'    => 'Chúng tôi đã nhận được yêu cầu báo giá của bạn cho họa tiết ' . $pattern['name'],
				default => 'We\'ve received your inquiry about ' . $pattern['name'],
			};
		}

		return match ( $locale ) {
			'zh'    => '我们已收到您的询盘',
			'es'    => 'Hemos recibido su consulta',
			'pt-br' => 'Recebemos sua consulta',
			'de'    => 'Wir haben Ihre Anfrage erhalten',
			'fr'    => 'Nous avons bien reçu votre demande',
			'ar'    => 'لقد استلمنا استفسارك',
			'id'    => 'Kami telah menerima permintaan Anda',
			'vi'    => 'Chúng tôi đã nhận được yêu cầu báo giá của bạn',
			default => 'We\'ve received your inquiry',
		};
	}

	/**
	 * Localized email greeting.
	 *
	 * @param string $name   The customer name.
	 * @param string $locale The canonical locale.
	 * @return string
	 */
	private static function email_greeting( string $name, string $locale ): string {
		return match ( $locale ) {
			'zh'    => $name . ' 您好，',
			'es'    => 'Hola, ' . $name . ':',
			'pt-br' => 'Olá, ' . $name . ',',
			'de'    => 'Hallo ' . $name . ',',
			'fr'    => 'Bonjour ' . $name . ',',
			'ar'    => 'مرحباً ' . $name . '،',
			'id'    => 'Halo ' . $name . ',',
			'vi'    => 'Xin chào ' . $name . ',',
			default => 'Hello ' . $name . ',',
		};
	}

	/**
	 * Localized email thank you line.
	 *
	 * @param array{name: string, code: string}|null $pattern The pattern.
	 * @param string                                 $locale  The canonical locale.
	 * @return string
	 */
	private static function email_thank_you( ?array $pattern, string $locale ): string {
		if ( null !== $pattern ) {
			$code_part = '' !== $pattern['code'] ? ' (' . $pattern['code'] . ')' : '';
			return match ( $locale ) {
				'zh'    => '感谢您关于图案 ' . $pattern['name'] . $code_part . ' 的询盘 — 本邮件确认我们已顺利收到您的信息。',
				'es'    => 'Gracias por su consulta sobre el patrón ' . $pattern['name'] . $code_part . '; este correo confirma que la hemos recibido.',
				'pt-br' => 'Obrigado por sua consulta sobre o padrão ' . $pattern['name'] . $code_part . ' — este e-mail confirma que a recebemos.',
				'de'    => 'Vielen Dank für Ihre Anfrage zu Muster ' . $pattern['name'] . $code_part . ' – diese E-Mail bestätigt den Eingang Ihrer Anfrage.',
				'fr'    => 'Merci pour votre demande concernant le motif ' . $pattern['name'] . $code_part . ' — cet e-mail confirme que nous l\'avons bien reçue.',
				'ar'    => 'شكراً لاستفسارك بخصوص نمط ' . $pattern['name'] . $code_part . ' — تؤكد هذه الرسالة استلامنا له.',
				'id'    => 'Terima kasih atas permintaan Anda mengenai pola ' . $pattern['name'] . $code_part . ' — email ini mengonfirmasi bahwa kami telah menerimanya.',
				'vi'    => 'Cảm ơn bạn đã gửi yêu cầu báo giá cho họa tiết ' . $pattern['name'] . $code_part . ' — email này xác nhận chúng tôi đã nhận được thông tin của bạn.',
				default => 'Thank you for your inquiry about ' . $pattern['name'] . $code_part . ' — this email confirms we have received it.',
			};
		}

		return match ( $locale ) {
			'zh'    => '感谢您的询盘 — 本邮件确认我们已顺利收到您的信息。',
			'es'    => 'Gracias por su consulta; este correo confirma que la hemos recibido.',
			'pt-br' => 'Obrigado por sua consulta — este e-mail confirma que a recebemos.',
			'de'    => 'Vielen Dank für Ihre Anfrage – diese E-Mail bestätigt den Eingang Ihrer Anfrage.',
			'fr'    => 'Merci pour votre demande — cet e-mail confirme que nous l\'avons bien reçue.',
			'ar'    => 'شكراً لاستفسارك — تؤكد هذه الرسالة استلامنا له.',
			'id'    => 'Terima kasih atas permintaan Anda — email ini mengonfirmasi bahwa kami telah menerimanya.',
			'vi'    => 'Cảm ơn bạn đã gửi yêu cầu báo giá — email này xác nhận chúng tôi đã nhận được thông tin của bạn.',
			default => 'Thank you for your inquiry — this email confirms we have received it.',
		};
	}

	/**
	 * Localized response promise & engineering drawings instructions.
	 *
	 * @param string $locale The canonical locale.
	 * @return string
	 */
	private static function email_promise( string $locale ): string {
		return match ( $locale ) {
			'zh'    => '我们的工程团队将在工作日 24 小时内回复，为您提供图案排版可行性、304 / 316 不锈钢材质方案及详细报价。若您已提交尺寸、数量或工期要求，我们将在回复中逐一解答。如您有图纸或现场照片，直接回复本邮件并附带附件，有助于我们更快速地为您核算报价。',
			'es'    => 'Nuestro equipo le responderá en un plazo de 24 horas en días laborables con la disponibilidad del patrón, opciones en acero inoxidable 304 / 316 y precios. Si nos envió dimensiones, cantidades o plazos, los abordaremos en dicha respuesta. Si dispone de planos o fotos del lugar, adjuntarlos a su respuesta nos ayudará a cotizar con mayor rapidez.',
			'pt-br' => 'Nossa equipe responde em até 24 horas em dias úteis com a disponibilidade do padrão, opções em aço inoxidável 304 / 316 e preços. Se você enviou dimensões, quantidades ou prazos, nós os abordaremos nessa resposta. Se você tiver desenhos técnicos ou fotos do local, anexá-los à sua resposta nos ajuda a preparar o orçamento mais rapidamente.',
			'de'    => 'Unser Team antwortet an Werktagen innerhalb von 24 Stunden mit Informationen zur Musterverfügbarkeit, Optionen für Edelstahl 304 / 316 und Preisangaben. Falls Sie Maße, Mengen oder einen Zeitplan übermittelt haben, gehen wir direkt darauf ein. Wenn Sie Zeichnungen oder Fotos vor Ort haben, hilft uns das Anhängen an Ihre Antwort, das Angebot noch schneller zu erstellen.',
			'fr'    => 'Notre équipe vous répondra sous 24 heures les jours ouvrés avec la disponibilité du motif, les options en acier inoxydable 304 / 316 et nos tarifs. Si vous avez indiqué des dimensions, quantités ou délais, nous les traiterons dans cette réponse. Si vous disposez de plans ou de photos du chantier, les joindre à votre réponse nous permettra d\'établir votre devis plus rapidement.',
			'ar'    => 'يرد فريقنا خلال 24 ساعة في أيام العمل موضحاً مدى توفر النمط، وخيارات الفولاذ المقاوم للصدأ 304 / 316 والأسعار. إذا كنت قد أرسلت أبعاداً أو كميات أو جدولاً زمنياً، فسنقوم بالرد عليها بالتفصيل. إذا كانت لديك مخططات أو صور للموقع، فإن إرفاقها بردك على هذه الرسالة يساعدنا في تقديم عرض الأسعار بشكل أسرع.',
			'id'    => 'Tim kami akan membalas dalam waktu 24 jam pada hari kerja dengan ketersediaan pola, opsi material stainless steel 304 / 316, dan penawaran harga. Jika Anda mencantumkan dimensi, jumlah, atau jadwal proyek, kami akan membahasnya dalam balasan tersebut. Jika Anda memiliki gambar kerja atau foto lokasi, melampirkannya pada balasan email Anda akan membantu kami memberikan penawaran lebih cepat.',
			'vi'    => 'Đội ngũ kỹ sư của chúng tôi sẽ phản hồi trong vòng 24 giờ vào các ngày làm việc kèm thông tin về tính khả thi của họa tiết, tùy chọn vật liệu inox 304 / 316 và báo giá chi tiết. Nếu bạn đã gửi kích thước, số lượng hoặc tiến độ, chúng tôi sẽ giải đáp cụ thể trong phản hồi. Nếu bạn có bản vẽ hoặc ảnh chụp thực tế, việc đính kèm khi trả lời email này sẽ giúp chúng tôi lập báo giá nhanh hơn.',
			default => 'Our team replies within 24 hours on business days with pattern availability, material options and pricing. If you sent dimensions, quantities or a timeline, we will address them in that reply. If you have drawings or site photos, attaching them to your reply helps us quote faster.',
		};
	}

	/**
	 * Localized faster channels introduction.
	 *
	 * @param string $locale The canonical locale.
	 * @return string
	 */
	private static function email_channels_intro( string $locale ): string {
		return match ( $locale ) {
			'zh'    => '需要更快联系我们？请直接回复本邮件，或通过以下方式：',
			'es'    => '¿Necesita comunicarse antes? Responda directamente a este correo o utilice:',
			'pt-br' => 'Precisa falar conosco mais rápido? Responda diretamente a este e-mail ou utilize:',
			'de'    => 'Möchten Sie uns schneller erreichen? Antworten Sie direkt auf diese E-Mail oder nutzen Sie:',
			'fr'    => 'Besoin de nous joindre plus rapidement ? Répondez directement à cet e-mail ou utilisez :',
			'ar'    => 'هل تحتاج للتواصل معنا بشكل عاجل؟ يمكنك الرد مباشرة على هذا البريد الإلكتروني، أو عبر:',
			'id'    => 'Perlu menghubungi kami lebih cepat? Balas langsung email ini, atau gunakan:',
			'vi'    => 'Cần liên hệ nhanh hơn? Hãy trả lời trực tiếp email này, hoặc liên hệ qua:',
			default => 'Need to reach us sooner? Reply directly to this email, or use:',
		};
	}

	/**
	 * Localized sign-off line.
	 *
	 * @param string $locale The canonical locale.
	 * @return string
	 */
	private static function email_signoff( string $locale ): string {
		return match ( $locale ) {
			'zh'    => '顺祝商祺，',
			'es'    => 'Atentamente,',
			'pt-br' => 'Atenciosamente,',
			'de'    => 'Mit freundlichen Grüßen',
			'fr'    => 'Cordialement,',
			'ar'    => 'مع أطيب التحيات،',
			'id'    => 'Salam hangat,',
			'vi'    => 'Trân trọng,',
			default => 'Kind regards,',
		};
	}

	/**
	 * Localized team name.
	 *
	 * @param string $locale The canonical locale.
	 * @return string
	 */
	private static function email_team( string $locale ): string {
		return match ( $locale ) {
			'zh'    => 'MetalDivider 团队',
			'es'    => 'El equipo de MetalDivider',
			'pt-br' => 'Equipe MetalDivider',
			'de'    => 'Ihr MetalDivider-Team',
			'fr'    => 'L\'équipe MetalDivider',
			'ar'    => 'فريق MetalDivider',
			'id'    => 'Tim MetalDivider',
			'vi'    => 'Đội ngũ MetalDivider',
			default => 'The MetalDivider team',
		};
	}
}
