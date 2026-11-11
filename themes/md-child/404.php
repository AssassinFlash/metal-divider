<?php
/**
 * Custom architectural 404 template for Metal Divider (9 languages).
 *
 * Replaces Picostrap's default cartoon 404 with an obsidian-and-gold
 * architectural directory face. Notice picostrap5's header.php already opens
 * `<main id="theme-main">` and footer.php closes `</main>`, so this template
 * renders a `<section>` directly inside the single document `<main>`.
 *
 * @package md-child
 */

defined( 'ABSPATH' ) || exit;

( static function (): void {
	$lang = class_exists( '\MetalDivider\Site\I18n\Languages' )
		? \MetalDivider\Site\I18n\Languages::current()
		: 'en';

	$url = static function ( string $path ) use ( $lang ): string {
		if ( class_exists( '\MetalDivider\Site\I18n\Languages' ) ) {
			return \MetalDivider\Site\I18n\Languages::home_url( $path, $lang );
		}
		return home_url( $path );
	};

	$copy = array(
		'en'    => array(
			'eyebrow'       => '404 · PAGE NOT FOUND',
			'title'         => 'This architectural reference has moved or does not exist.',
			'lede'          => 'The link you followed may be outdated or mistyped. Explore our 146 laser-cut, perforated, and woven stainless steel (304 / 316) screen patterns, review completed hospitality installations, or send your drawings directly to our engineering team.',
			'cta_home'      => 'Return to Home',
			'cta_home_sub'  => 'Overview, applications & fabrication capabilities',
			'cta_patterns'  => 'Browse 146 Patterns',
			'cta_pat_sub'   => 'Filter stainless steel 304 / 316 patterns by series',
			'cta_gallery'   => 'View Project Gallery',
			'cta_gal_sub'   => 'Installed hotel, facade & commercial partitions',
			'cta_quote'     => 'Request a Project Quote',
			'cta_quote_sub' => 'Upload CAD / PDF specs for an itemized quotation',
		),
		'zh'    => array(
			'eyebrow'       => '404 · 页面未找到',
			'title'         => '您访问的建筑屏风页面已迁移或不存在。',
			'lede'          => '该链接可能已过期或输入有误。您可以直接浏览全部 146 款 304 / 316 不锈钢激光切割与穿孔屏风图案、查阅全球酒店与商业空间落地案例，或直接向工程团队提交图纸获取分项报价。',
			'cta_home'      => '返回当前语言首页',
			'cta_home_sub'  => '了解定制工艺、应用场景与工厂制造体系',
			'cta_patterns'  => '浏览 146 款图案库',
			'cta_pat_sub'   => '按系列筛选 304 / 316 不锈钢建筑屏风图案',
			'cta_gallery'   => '查看工程案例画廊',
			'cta_gal_sub'   => '酒店大堂、建筑立面与商业隔断实景案例',
			'cta_quote'     => '直接索取方案报价',
			'cta_quote_sub' => '提交尺寸或图纸，获取工程深化与报价反馈',
		),
		'es'    => array(
			'eyebrow'       => '404 · PÁGINA NO ENCONTRADA',
			'title'         => 'Esta referencia arquitectónica se ha movido o no existe.',
			'lede'          => 'Es posible que el enlace esté desactualizado o contenga un error. Explore nuestros 146 patrones de celosías y paneles divisorios en acero inoxidable (304 / 316), revise proyectos ejecutados o envíe sus planos para recibir una cotización detallada.',
			'cta_home'      => 'Volver al inicio',
			'cta_home_sub'  => 'Resumen general, aplicaciones y fabricación',
			'cta_patterns'  => 'Explorar 146 patrones',
			'cta_pat_sub'   => 'Catálogo completo en acero inoxidable 304 / 316',
			'cta_gallery'   => 'Ver galería de proyectos',
			'cta_gal_sub'   => 'Instalaciones reales en hoteles, fachadas e interiores',
			'cta_quote'     => 'Solicitar cotización',
			'cta_quote_sub' => 'Envíe medidas o planos CAD / PDF a ingeniería',
		),
		'pt-br' => array(
			'eyebrow'       => '404 · PÁGINA NÃO ENCONTRADA',
			'title'         => 'Esta referência arquitetônica foi movida ou não existe.',
			'lede'          => 'O endereço acessado pode estar desatualizado ou incorreto. Navegue pelos nossos 146 padrões de painéis e divisórias em aço inoxidável (304 / 316), conheça projetos entregues ou envie seus desenhos técnicos para orçamento.',
			'cta_home'      => 'Voltar para a página inicial',
			'cta_home_sub'  => 'Visão geral, aplicações e processos de fabricação',
			'cta_patterns'  => 'Explorar 146 padrões',
			'cta_pat_sub'   => 'Biblioteca completa em aço inoxidável 304 / 316',
			'cta_gallery'   => 'Ver galeria de projetos',
			'cta_gal_sub'   => 'Obras instaladas em hotéis, fachadas e ambientes comerciais',
			'cta_quote'     => 'Solicitar orçamento',
			'cta_quote_sub' => 'Envie dimensões ou arquivos CAD / PDF para análise',
		),
		'de'    => array(
			'eyebrow'       => '404 · SEITE NICHT GEFUNDEN',
			'title'         => 'Diese Architektur-Referenz wurde verschoben oder existiert nicht.',
			'lede'          => 'Der aufgerufene Link ist möglicherweise veraltet oder fehlerhaft. Entdecken Sie unsere 146 Edelstahl-Muster (304 / 316) für Raumteiler und Fassadenelemente, prüfen Sie realisierte Referenzprojekte oder senden Sie Ihre Zeichnungen direkt für ein Projektangebot.',
			'cta_home'      => 'Zurück zur Startseite',
			'cta_home_sub'  => 'Überblick, Einsatzbereiche und Fertigungstechnik',
			'cta_patterns'  => '146 Muster durchsuchen',
			'cta_pat_sub'   => 'Alle Serien aus Edelstahl 304 / 316 im Überblick',
			'cta_gallery'   => 'Projektgalerie ansehen',
			'cta_gal_sub'   => 'Realisierte Hotel-, Fassaden- und Innenraumprojekte',
			'cta_quote'     => 'Projektangebot anfordern',
			'cta_quote_sub' => 'Maße oder CAD-/PDF-Pläne zur Prüfung einreichen',
		),
		'fr'    => array(
			'eyebrow'       => '404 · PAGE INTROUVABLE',
			'title'         => 'Cette référence architecturale a été déplacée ou n’existe pas.',
			'lede'          => 'Le lien suivi est peut-être obsolète ou erroné. Parcourez nos 146 motifs de claustras et panneaux en acier inoxydable (304 / 316), consultez nos réalisations hôtelières et architecturales ou transmettez vos plans pour un chiffrage détaillé.',
			'cta_home'      => 'Retour à l’accueil',
			'cta_home_sub'  => 'Présentation, applications et procédés d’usinage',
			'cta_patterns'  => 'Parcourir les 146 motifs',
			'cta_pat_sub'   => 'Catalogue complet en acier inoxydable 304 / 316',
			'cta_gallery'   => 'Voir la galerie de projets',
			'cta_gal_sub'   => 'Réalisations installées en hôtellerie et façades',
			'cta_quote'     => 'Demander un devis projet',
			'cta_quote_sub' => 'Transmettez vos cotes ou plans CAO / PDF',
		),
		'ar'    => array(
			'eyebrow'       => '404 · الصفحة غير موجودة',
			'title'         => 'هذا المرجع المعماري قد تم نقله أو أنه غير موجود.',
			'lede'          => 'قد يكون الرابط الذي اتبعته قديماً أو غير صحيح. استعرض مكتبتنا التي تضم 146 نمطاً من فواصل وشاشات الفولاذ المقاوم للصدأ (304 / 316)، أو شاهد معرض المشاريع المنفذة، أو أرسل مخططاتك الهندسية للحصول على عرض سعر مفصل.',
			'cta_home'      => 'العودة إلى الصفحة الرئيسية',
			'cta_home_sub'  => 'نظرة عامة على التطبيقات وقدرات التصنيع المعماري',
			'cta_patterns'  => 'تصفح 146 نمطاً معمارياً',
			'cta_pat_sub'   => 'استعراض أنماط الفولاذ المقاوم للصدأ 304 / 316 حسب السلسلة',
			'cta_gallery'   => 'مشاهدة معرض المشاريع',
			'cta_gal_sub'   => 'مشاريع منفذة للفنادق والواجهات والفواصل الداخلية',
			'cta_quote'     => 'طلب عرض سعر للمشروع',
			'cta_quote_sub' => 'أرسل الأبعاد أو ملفات CAD / PDF للمراجعة الهندسية',
		),
		'id'    => array(
			'eyebrow'       => '404 · HALAMAN TIDAK DITEMUKAN',
			'title'         => 'Referensi arsitektur ini telah dipindahkan atau tidak tersedia.',
			'lede'          => 'Tautan yang Anda buka mungkin sudah kedaluwarsa atau salah ketik. Jelajahi 146 motif partisi dan fasad baja tahan karat (304 / 316) kami, lihat galeri proyek terpasang, atau kirim gambar kerja Anda untuk penawaran proyek.',
			'cta_home'      => 'Kembali ke Beranda',
			'cta_home_sub'  => 'Ringkasan aplikasi, spesifikasi & fabrikasi pabrik',
			'cta_patterns'  => 'Jelajahi 146 Motif',
			'cta_pat_sub'   => 'Katalog lengkap panel baja tahan karat 304 / 316',
			'cta_gallery'   => 'Lihat Galeri Proyek',
			'cta_gal_sub'   => 'Instalasi hotel, fasad & ruang komersial',
			'cta_quote'     => 'Minta Penawaran Proyek',
			'cta_quote_sub' => 'Unggah ukuran atau gambar CAD / PDF untuk estimasi',
		),
		'vi'    => array(
			'eyebrow'       => '404 · KHÔNG TÌM THẤY TRANG',
			'title'         => 'Trang tham chiếu kiến trúc này đã được chuyển đi hoặc không tồn tại.',
			'lede'          => 'Liên kết bạn vừa mở có thể đã cũ hoặc nhập sai. Hãy khám phá bộ sưu tập 146 hoa văn vách ngăn thép không gỉ (304 / 316), xem thư viện công trình thực tế hoặc gửi bản vẽ kỹ thuật để nhận báo giá chi tiết.',
			'cta_home'      => 'Quay về Trang chủ',
			'cta_home_sub'  => 'Tổng quan ứng dụng, quy cách & năng lực gia công',
			'cta_patterns'  => 'Xem 146 Mẫu Hoa Văn',
			'cta_pat_sub'   => 'Tra cứu toàn bộ dòng hoa văn thép không gỉ 304 / 316',
			'cta_gallery'   => 'Xem Thư Viện Dự Án',
			'cta_gal_sub'   => 'Công trình khách sạn, mặt dựng & nội thất thương mại',
			'cta_quote'     => 'Yêu Cầu Báo Giá Dự Án',
			'cta_quote_sub' => 'Gửi kích thước hoặc bản vẽ CAD / PDF cho đội kỹ thuật',
		),
	);

	$strings = $copy[ $lang ] ?? $copy['en'];

	get_header();
	?>
	<section class="md-404" aria-labelledby="md-404-title">
		<div class="md-404__lattice" aria-hidden="true"></div>
		<div class="md-404__inner">
			<div class="md-404__header">
				<p class="md-404__eyebrow"><?php echo esc_html( $strings['eyebrow'] ); ?></p>
				<h1 id="md-404-title" class="md-404__title"><?php echo esc_html( $strings['title'] ); ?></h1>
				<p class="md-404__lede"><?php echo esc_html( $strings['lede'] ); ?></p>
			</div>

			<div class="md-404__grid">
				<a class="md-404__card" href="<?php echo esc_url( $url( '/' ) ); ?>">
					<span class="md-404__card-index" aria-hidden="true">01</span>
					<span class="md-404__card-body">
						<span class="md-404__card-title"><?php echo esc_html( $strings['cta_home'] ); ?></span>
						<span class="md-404__card-sub"><?php echo esc_html( $strings['cta_home_sub'] ); ?></span>
					</span>
					<span class="md-404__card-arrow" aria-hidden="true">&rarr;</span>
				</a>

				<a class="md-404__card" href="<?php echo esc_url( $url( '/patterns/' ) ); ?>">
					<span class="md-404__card-index" aria-hidden="true">02</span>
					<span class="md-404__card-body">
						<span class="md-404__card-title"><?php echo esc_html( $strings['cta_patterns'] ); ?></span>
						<span class="md-404__card-sub"><?php echo esc_html( $strings['cta_pat_sub'] ); ?></span>
					</span>
					<span class="md-404__card-arrow" aria-hidden="true">&rarr;</span>
				</a>

				<a class="md-404__card" href="<?php echo esc_url( $url( '/project-gallery/' ) ); ?>">
					<span class="md-404__card-index" aria-hidden="true">03</span>
					<span class="md-404__card-body">
						<span class="md-404__card-title"><?php echo esc_html( $strings['cta_gallery'] ); ?></span>
						<span class="md-404__card-sub"><?php echo esc_html( $strings['cta_gal_sub'] ); ?></span>
					</span>
					<span class="md-404__card-arrow" aria-hidden="true">&rarr;</span>
				</a>

				<a
					class="md-404__card md-404__card--primary"
					href="#open-quote"
					data-quote-source="error-404"
					data-source-page="404"
					data-source-module="404-hero"
					data-source-button="quote"
				>
					<span class="md-404__card-index" aria-hidden="true">04</span>
					<span class="md-404__card-body">
						<span class="md-404__card-title"><?php echo esc_html( $strings['cta_quote'] ); ?></span>
						<span class="md-404__card-sub"><?php echo esc_html( $strings['cta_quote_sub'] ); ?></span>
					</span>
					<span class="md-404__card-arrow" aria-hidden="true">&rarr;</span>
				</a>
			</div>
		</div>
	</section>
	<?php
	get_footer();
} )();
