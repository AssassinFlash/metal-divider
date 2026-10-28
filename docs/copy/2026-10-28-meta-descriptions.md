# 页面 meta description（2026-10-28）

本文件早先的草案已推翻，不是定稿。定稿是下面这 17 句，逐字写入 `plugins/md-site/includes/Seo/Config.php` 的 `page_metas()`，键为 `_seopress_titles_desc`。全局 `%%post_excerpt%%` 模板不改。inquiry、privacy、blog 的既有 title，以及 `inquiry/thank-you` 的 noindex，都不在这批里动。7 个产品族页只补描述，不另加章节外的数字或承诺。

这些描述没有隐含条件。原先草案里的两个业主问题（外部下载文件是否获准使用、询盘回复时限选哪一种）不构成这 10 句的前提，也不再悬着。定稿不把外部文件说成本站文件，也不在三种回复时限里另选一种。

## 依据

对抗复核以仓库模板和已有裁定为准，不采信草案自称的事实。写入面是页面 metabox：SEOPress 先读 `_seopress_titles_desc`，空了才用 `%%post_excerpt%%`。`/blog/` 是 `page_for_posts`，描述写在 slug `blog` 的页面上，与既有标题同一行，不写进文章档案模板。

草案被推翻的原因，按页：

- `/collections/`：枢纽页写的是按 privacy、airflow、light、project use 选系列，按钮是 Send Reference。草案写了该页没有的 open area、visual density 和 request a quote。
- `/inquiry/`：页面同时有 one business day、Within 24 hours、same day。草案又加了页面没有的 pattern mockups，并选定一种时限。
- `/faqs/`：可见引言是 request 文件。下载链接指向 Zahner 与 Brilliant 的外部 URL。草案把它们写成站内可下载的 304/316 技术文件。
- `/about-us/`：首段只写 laser-cut、PVD、出货前检查。草案加了 perforated。
- `/manufacturing-process/`：可见引言是 drawing review 到 shipment。草案写了这段里没有的 pattern lock、crated delivery、who signs off。
- `/project-gallery/`：H1 是 Selected Interior Partition Projects。抽到的 alt 写 visualization。草案写了 Installed。
- `/laser-cut-geometric-metal-screens/`：H1 是 Laser-Cut Stainless Steel Screens，副题是三种室内图案方向。草案写了该段没有的 open area 和 mockups。
- `/blog/`：H1 是 Knowledge for your next project。草案列举了模板没有的文章主题。
- `/custom-metal-divider-screens/`：可见正文是绘图、制造、表面处理和出口包装。草案写了页面没有的 mockup。
- `/privacy/`：权利是 access、correct、delete。草案写成 a copy。

## 定稿

| 页面 | `_seopress_titles_desc` |
|---|---|
| `collections` | Decorative metal screen pattern series. Choose a family by privacy, airflow, light, and project use, then send a reference. |
| `inquiry` | Request your quote. Write what the project needs. A person replies with questions, or with a quote. |
| `faqs` | Answers on 304 and 316 stainless steel, panel sizes, lead times, and installation for architectural screens. |
| `about-us` | Laser-cut screens finished in your chosen PVD color, checked before they ship, and delivered exactly as approved. |
| `manufacturing-process` | From drawing review to shipment: stainless screens matched to laser, perforation, or expanded metal, with route-specific tolerances and quality gates. |
| `project-gallery` | Interior partition screen gallery for hotels, commercial spaces and homes. Filter by category and open a case to view its photo set. |
| `laser-cut-geometric-metal-screens` | Laser-cut stainless steel (304 / 316) screens in three interior pattern directions. Compare the directions, then request a quote. |
| `blog` | Metal Divider journal: technical guidance for architects, contractors and dealers. Browse patterns, read the FAQs, or send an inquiry. |
| `custom-metal-divider-screens` | Custom stainless steel (304 / 316) divider screens from your drawing or an existing pattern. Send dimensions and the application to request a quote. |
| `privacy` | How metal-divider.com handles inquiry data: what we collect, why, how long we keep it, and how to ask about access or deletion. |
| `chinese-style-artistic-screens` | Chinese-style and artistic stainless steel screens for hotels, restaurants and luxury homes. Send the pattern, dimensions and finish for a project spec. |
| `glass-hybrid-screens` | Glass and hybrid screens pairing stainless steel frames with glass for zoning and privacy in premium interiors. Send the opening and glass direction for a spec. |
| `outdoor-privacy-landscape-screens` | Outdoor privacy and landscape screens in 304 and 316 stainless steel, specified for coastal, alpine and courtyard conditions. Send the site and dimensions. |
| `expanded-metal-panels` | Expanded stainless steel mesh panels for facades, partitions and ceilings. Compare diamond, hexagonal and fine patterns, then send the panel schedule. |
| `laser-cut-panels` | Laser-cut stainless steel panels cut to your drawing in 304 or 316, brushed or PVD finish. Send a DXF for a manufacturability review and quote. |
| `perforated-panels` | Perforated stainless steel panels in 304 or 316, punched to your pattern and open area. Send the hole pattern, open area and panel size for a quote. |
| `stainless-steel-screens` | Stainless steel screen systems for facades, partitions and outdoor privacy. Compare laser-cut, perforated, expanded and hybrid directions, then send the brief. |
