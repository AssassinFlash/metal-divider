# 公开客服问答与询盘语料：许可、清洗、有界导入

- 票：[研究：公开客服问答与询盘语料（许可、清洗、有界导入）](https://github.com/AssassinFlash/metal-divider/issues/157)
- 父地图：[Wayfinder map：AI 客服与坐席工作台持续功能优化](https://github.com/AssassinFlash/metal-divider/issues/148)（本文件不改地图）
- 容量依据：[#103](https://github.com/AssassinFlash/metal-divider/issues/103)，研究文档在 `research/wordpress-native-zero-cost` @ `45fb5b4` 的 `docs/research/2026-10-26-wp-native-kb-capacity.md`。该文写明：本站现实规模 200–600 块；超过约 3000 块应切换混合检索；10000 块是共享主机上纯 PHP 余弦的不可用线。本票不重测容量，只把导入量卡在 200–600 块之内。
- 不重叠：[#156](https://github.com/AssassinFlash/metal-divider/issues/156) 是访客界面与视觉基准，不在这里讨论。
- 日期：2026-10-27
- 分支：`research/public-support-corpora`（自 `origin/main` 拉出后变基到 `1089806`，不合并、不开 PR）
- 工作树：`.scratch/research-157`（本票唯一临时工作副本）
- 用途：本票研究文档写作与分支推送
- 负责人：本票研究会话（GitHub assignee AssassinFlash）
- 回收条件：分支推送成功且票关闭后删除该工作树；不删除分支，不删除本文件

只回答：哪些公开、可合法取得、可清洗的客服问答 / 询盘语料，适合拿来给 staging 上的 Metal Divider AI 客服做答案质量和转人工判断。用途是真实问法驱动检索与转人工，不是微调基座模型。语料不进仓库，不带真实客户个人信息，导入量不得超过 #103 的现实规模。

不购买数据。样本只留在 `/tmp/md-157-*`，不进本仓库。

## 证据等级

| 等级 | 含义 |
| --- | --- |
| 实测 | 2026-10-27 读到的官方页面、官方文件头、官方 API 或本机下载的样本 |
| 官方宣称 | 数据集卡片或论文写了，本次没有把全文下完再数一遍 |
| 合理推断 | 由已读许可条款推出的使用边界，不是律师意见 |
| 无法确认 | 材料没写，或两份官方材料互相矛盾 |

## 结论速览

1. **没有找到可再分发的建筑金属屏风询盘语料。** 公开网页上有同业 FAQ，那些页面没有给出可再分发许可。问法类型可以改写进我们自己的测试集；原文不进仓库、不进 staging。
2. **推荐的有界包只有三块，合计不超过 600 块。** CLINC OOS 提供“不在知识范围内”的问法；Banking77 提供短问句和意图标签；Bitext 提供带占位符的电商问法，用来测检索不该把订单/退款句答成图案规格。三者都不是建筑询盘，所以不能当答案来源，只能当问题来源。
3. **Twitter 客服集排除。** Kaggle 页面许可是 CC BY-NC-SA 4.0，并另写商业用途需联系作者。商业 staging 不用。
4. **Ubuntu Dialogue 与 MultiWOZ 不进第一批。** Ubuntu 的生成脚本是 Apache-2.0，聊天记录版权没有在本次读到的材料里写成可商用再分发。MultiWOZ 仓库的 MIT 只覆盖代码。两者体积也远超 600 块。
5. **语料不进公开仓库。** 即使许可允许计算使用，票面已经禁止把语料提交进仓库。CDLA-Sharing 与 CC BY-SA 的分享义务，只在我们把清洗后的子集再发布时触发。staging 内部使用不发布子集。

## 候选表

数字以 2026-10-27 读到的一手材料为准。卡片与文件对不上的地方两列都写，不取其中一个当事实。

| 来源 | 许可 | 规模 | 语言 | 字段 | PII | 噪声 | 下载 | 本票处置 |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| [CLINC OOS](https://huggingface.co/datasets/clinc/clinc_oos) / [oos-eval](https://github.com/clinc/oos-eval) | CC BY 3.0（HF 卡片与仓库 `LICENSE` 一致） | 官方 JSON 23700 条：train 15000、val 3000、test 4500、oos_train 100、oos_val 100、oos_test 1000。论文写 23700 条、150 个意图、10 个领域 | en | `[text, label]` | 众包改写，不是真实客户记录。本机解析 23700 条，含 `@` 的为 0 | 短句，长度 2–136 字符，平均 39.9。领域是银行/出行/家务，不是建筑 | `data/data_full.json` 2495390 字节，HTTP 200。HF `small/train` parquet 171552 字节，文件头 `PAR1` | 第一批。只抽 OOS 与少量 in-scope，不导 23700 |
| [Banking77](https://huggingface.co/datasets/PolyAI/banking77) / [task-specific-datasets](https://github.com/PolyAI-LDN/task-specific-datasets) | CC BY 4.0（HF 卡片与仓库 `LICENSE` 一致） | 论文与 HF 卡片：13083 条、77 个意图。本机 GitHub CSV：train 10016 条（文件 839073 字节）、test 3084 条（239961 字节）。HF `dataset_infos.json` 写 train 10003、test 3080。差 13+4，原因本次未对上 | en | `text`, `category` | 卡片写成客户服务问句。样本前几行是卡片到达/激活，无邮箱 | 银行专有名词多。不能当本站答案 | 仓库 CSV 直接 HTTP 200 | 第一批。只抽短问句做检索压力，不导全量 |
| [Bitext 客服 27K](https://huggingface.co/datasets/bitext/Bitext-customer-support-llm-chatbot-training-dataset) | CDLA-Sharing-1.0（卡片）。许可正文以 [SPDX 文本](https://spdx.org/licenses/CDLA-Sharing-1.0.html) 为准，cdla.dev 页面本次只返回脚本壳 | 卡片写 26872 对、27 个意图、10 个类。本机流式计数 CSV 91336 行（含表头则数据行少 1）。文件 `Content-Length` 19202474。两数对不上，不采用卡片的 26872 当文件事实 | en | `flags`, `instruction`, `category`, `intent`, `response` | 合成。样本用 `{{Order Number}}` 一类占位符，不是真人订单号 | 类是订单/退款/配送/账户。对建筑站是负例，不是答案 | HF CSV，gated=false | 第一批负例。只抽问句，不导回答，不导全文件 |
| [WISE 金属屏风 FAQ](https://www.fswisebuilding.com/faq/) 及同站 perforated / outdoor / laser-cut / technical 页 | 页面有 2026 版权声明。没有读到再分发许可 | 本次抽出十几条问法，不是语料库 | en | 网页 FAQ，无稳定字段 | 公司公开营销文，无客户个人信息 | 营销口径（交期、合金、表面处理）不能当成 Metal Divider 的规格 | 页面 HTTP 200。摘录只在 `/tmp/md-157-domain-faqs.txt` | 不导入。问法类型改写后才能进自有测试集 |
| [Schema-Guided Dialogue](https://github.com/google-research-datasets/dstc8-schema-guided-dialogue) | CC BY-SA 4.0（README 与 `LICENSE.txt` 一致） | README 写 over 20k 段对话、20 个领域。本次没有下完整包再数行 | en | `dialogue_id`, `services`, turns, user `state` | 模拟器加付费众包，不是真实客户记录 | 多轮任务型，领域是银行/出行/天气等 | GitHub clone。分享则必须保持 CC BY-SA 4.0 并署名 | 不进第一批。体积和分享义务都不适合 staging 知识库 |
| [MultiWOZ](https://github.com/budzianowski/multiwoz) | 仓库 `LICENSE` 是 MIT，版权行是 2019 Paweł Budzianowski，文本写的是 Software。HF `ConvLab/multiwoz21` 卡片写 apache-2.0。论文没有在本次抽取的页里给出对话版权 | README 写 10k dialogues。仓库内 zip：1.0 为 12639800 字节，2.0 为 13990982，2.1 为 20241542 | en | 对话、belief state、dialogue act | Wizard-of-Oz 人写对话，不是本站客户 | 多领域任务型，与询盘规格无关 | 代码可下。对话商用许可无法确认 | 排除，直到对话许可有一手文件 |
| [Ubuntu Dialogue Corpus v1](https://www.cs.mcgill.ca/~jpineau/datasets/ubuntu-corpus-1.0/) | 生成脚本仓库 [ubuntu-ranking-dataset-creator](https://github.com/rkadlec/ubuntu-ranking-dataset-creator) 是 Apache-2.0。该许可不覆盖 IRC 日志。日志站 [irclogs.ubuntu.com](http://irclogs.ubuntu.com/) HTTP 200，没有在本次读到可商用再分发条款 | 论文 [W15-4640](https://aclanthology.org/W15-4640/) 表 2：930,000 dialogues，7,100,000 utterances，100,000,000 words。官方 tarball `Content-Length` 552499372，Last-Modified 2015-07-21 | en | 多轮，`__eou__` / `__eot__` | 公开 IRC。含用户名与技术环境，不是本站客户，但仍是真人发言 | 技术排障，不是询盘 | tarball HTTP 200。552MB，远超 600 块 | 排除。只可在另票澄清版权后，抽几十段多轮形状，且不进知识库 |
| [Twitter Customer Support](https://www.kaggle.com/datasets/thoughtvector/customer-support-on-twitter) | 页面写 CC BY-NC-SA 4.0。同页另写商业用途和完整集请联系 stuart@thoughtvector.io。第三方 [dialog-intent-induction LICENSE](https://github.com/asappresearch/dialog-intent-induction/blob/master/data/LICENSE) 也写该集为 CC BY-NC-SA 4.0 | 页面写 Over 3 million tweets and replies。HF 镜像 `gorkemsevinc/Customer_Support_on_Twitter` 无 license 标签，3982937 条，不能拿镜像许可当原集许可 | en | `tweet_id`, `author_id`, `inbound`, `created_at`, `text`, `response_tweet_id`, `in_response_to_tweet_id` | 页面写 author_id 已匿名、电话邮箱打成 `__email__`。仍是真人客服推文，NC 已足够排除 | 品牌闲聊、截断、多语言碎片 | Kaggle 需登录。本次浏览器抓到的是数据集页，不是 CSV | 排除。商业 staging 不用 |
| [MakTek FAQ](https://huggingface.co/datasets/MakTek/Customer_support_faqs_dataset) | Apache-2.0 | 文件 `train_expanded.json` 46485 字节、200 行；本机去重后 89 个问题、86 个答案。同一句 “How can I reset my password?” 出现 13 次 | en | instruction/response 式 FAQ | 通用电商句，无真人信息 | 重复膨胀 | HF 文件 HTTP 200 | 排除。不能当质量种子 |
| [StateSet 多轮](https://huggingface.co/datasets/stateset/customer-support-multiturn-v1) | Apache-2.0 | train 560 行、eval 240 行（卡片/文件行数，本次抽样未逐条人工读） | en | `expected_resolution`, `intent`, `policy_facts`, `user_turns`, `must_acknowledge`, `must_avoid` | 合成。样本含虚构订单号 | 策略标签有用，问法不是建筑询盘 | HF | 不进知识库。若以后要测“必须承认/必须避免”的策略，另票再抽，不在本票导入 |
| [Ask Ubuntu dump](https://archive.org/details/stackexchange) | archive.org 该 item 的 description 写用户内容为 CC BY-SA 4.0，链到 https://creativecommons.org/licenses/by-sa/4.0/ | `askubuntu.com.7z` 1071610861 字节，mtime 2024-04-07，md5 `eb73b916968b8580e10f208941991eb3`。item 更新时间 2025-06-24 | en | Stack Exchange posts XML | 官方写 anonymized。仍是真人帖 | 技术问答，1GB 级，远超 600 块 | 不下载 | 排除出第一批。署名和相同方式分享义务也不适合公开仓库 |

Hendrick 的 architectural / screen 页和 MetalTech 的 facade 文章，本次没有抽出 FAQ 块。Facebook 结果丢弃。换过 perforated metal、laser-cut screen、architectural metal、building material 的 FAQ / inquiry 问法之后，没有找到可再分发的 B2B 屏风询盘转储。

## 许可边界

这些是使用边界，不是法律意见。

- **CC BY 3.0 / 4.0（CLINC、Banking77）**：计算使用可以。若把子集再发布，必须署名。本票不发布子集，所以署名只留在本文件和导入脚本的来源注释里，不把原文提交进仓库。
- **CDLA-Sharing 1.0（Bitext）**：SPDX 文本允许 Use 与 Publish。Publish 清洗后的 Data 或 Enhanced Data 时，必须继续用同一协议、保留归属、标明改动。第 3.5 节不给 Computational Use 的 Results 加额外义务。用问句测机器人属于计算使用。把 CSV 提交进公开仓库属于发布，禁止。
- **CC BY-SA 4.0（SGD、Ask Ubuntu）**：再发布清洗子集必须保持 CC BY-SA 4.0。公开仓库不接收这类子集。staging 内部不发布可以后议，但第一批不需要它们。
- **CC BY-NC-SA 4.0（Twitter/Kaggle）**：非商业条款与商业询盘站冲突。排除。
- **代码许可 ≠ 数据许可（Ubuntu 生成脚本 Apache-2.0，MultiWOZ 仓库 MIT）**：不把代码许可证写成对话许可。
- **公开网页 ≠ 可再分发语料（WISE）**：能读到不等于能复制进知识库。

## 推荐包与清洗

第一批只做问题，不做答案导入。Rapls 的知识块仍然只来自本站 Woo 图案规格、已定稿页面和坐席确认过的 FAQ。公开语料回答建筑问题会把银行余额、订单号和别人的交期写进本站回答。

| 批次 | 来源 | 条数 | 进哪里 | 清洗 |
| --- | --- | --- | --- | --- |
| A | CLINC `oos_test` 抽 80，`oos_train`+`oos_val` 全取 200 里再抽 40，合计 120 | 120 | 评测集，不进知识库 | 保留原文问句和 `oos` 标签。丢掉长度 < 8 字符的残句。不改写，以便复现 |
| B | CLINC in-scope 抽 80，Banking77 train 抽 80 | 160 | 评测集，不进知识库 | 只留问句和原标签。标签映射成 `out_of_domain`，期望行为是转人工或拒答，不是检索本站图案 |
| C | Bitext 每个 category 抽 10 条 `instruction`，10 类共 100 | 100 | 评测集负例 | 丢掉 `response`。丢掉含 `{{...}}` 以外疑似真人标识的行（本抽样未见到）。问句期望为拒答/转人工 |
| D | 自有改写，不复制 WISE 原文 | 40–80 | 唯一允许进入知识库候选的一批 | 问法类型来自公开页（户外不锈钢还是铝、开孔率与遮挡、安装基层、图纸、交期、表面处理、沿海环境）。答案只写本站已发布规格；没有规格的标成知识缺口，不编造 |

A+B+C = 380 条评测问句。D 若 80 条全部被坐席确认，知识库新增也不超过 80 块，总占用仍在 #103 的 200–600 块现实规模内。不把 380 条评测问句写进知识库，否则银行和订单句会变成可检索答案。

清洗规则：

1. 不提交语料文件。下载目录固定 `/tmp/md-157-*`，任务结束不进仓库。
2. 不导入任何含邮箱、电话、订单号、姓名、公司名的行。占位符 `{{Order Number}}` 可以留在评测问句里，不能留在知识库。
3. 不导入他人回答。Bitext、WISE、Twitter 的回复文本一律不进 Rapls。
4. 英文原句保留。本站首期是英语站，不在本票做翻译扩充。
5. 单条知识块只承载一个已确认事实。评测问句不切块进索引。
6. 超过 600 块的导入脚本应拒绝执行。这一条等实现票写进导入器，本票不改代码。

## 验收

导入本身不在本票。下面的数是下一次 staging 评测该报的数，不是本次已跑的结果。

| 指标 | 定义 | 第一批线 |
| --- | --- | --- |
| 问法覆盖 | D 批 8 类问法（材料、开孔/遮挡、安装、图纸、交期、表面处理、环境、包装）里，至少 1 条能检索到本站已发布规格的类数 / 8 | 不设通过线。缺的类记成知识缺口，不拿公开网页的数字填 |
| 知识缺口率 | D 批中坐席标成“本站没有这条规格”的比例 | 只记录。缺口进 #151 已指出的未回答列表，不在本票改 Rapls |
| 转人工准确 | B+C 的 260 条域外/负例里，系统拒答或转人工、且没有引用本站图案规格当答案的比例 | 低于 90% 则停用该检索配置，不调阈值到“看起来能过” |
| 域内误转 | A 批不算域内。D 批里已有规格的问句被转人工的比例 | 高于 20% 则先查知识块有没有进索引，不先改模型 |
| 时延 | 单问检索加生成。容量文档的量级是 200–600 块时检索 < 0.5s；本票不重测 | 评测时单问端到端超过 3s 的比例单独报，不和准确率混成一个分 |

评测集 380 条可以分三次跑，每次 120–140，避免把 staging 当压测。不把 Ubuntu tarball、Ask Ubuntu 1GB 或 Twitter 三百万条倒进共享主机。

## 无法确认

- Banking77 的收集方式：论文 HTML 本次没有定位到众包或真实客户导出的句子。规模和许可已确认，收集渠道未确认。
- Banking77 的 13+4 条行数差：GitHub CSV 与 HF `dataset_infos.json` 不一致。不发明原因。
- Bitext 的 26872 与 91336：卡片与 CSV 行数不一致。使用时以实际下载文件为准。
- MultiWOZ 对话版权：代码 MIT 与 HF apache-2.0 都不是对话许可的一手证明。
- Ubuntu IRC 日志的再分发条款：本次没有读到。
- WISE 页面版权人是否允许摘录进第三方知识库：没有读到授权，按不允许处理。
- CDLA 官网页面：cdla.dev 的 Sharing 1.0 URL 本次没有返回许可正文，改以 SPDX 文本为准。

## 来源

- CLINC 卡片：https://huggingface.co/datasets/clinc/clinc_oos
- CLINC 仓库与 JSON：https://github.com/clinc/oos-eval
- CLINC 论文：https://aclanthology.org/D19-1131/
- Banking77 卡片：https://huggingface.co/datasets/PolyAI/banking77
- Banking77 仓库：https://github.com/PolyAI-LDN/task-specific-datasets
- Banking77 论文：https://arxiv.org/abs/2003.04807
- Bitext 卡片：https://huggingface.co/datasets/bitext/Bitext-customer-support-llm-chatbot-training-dataset
- CDLA-Sharing 1.0 正文：https://spdx.org/licenses/CDLA-Sharing-1.0.html
- Ubuntu 论文：https://aclanthology.org/W15-4640/
- Ubuntu tarball：https://www.cs.mcgill.ca/~jpineau/datasets/ubuntu-corpus-1.0/ubuntu_dialogs.tgz
- Ubuntu 生成脚本：https://github.com/rkadlec/ubuntu-ranking-dataset-creator
- SGD：https://github.com/google-research-datasets/dstc8-schema-guided-dialogue
- MultiWOZ：https://github.com/budzianowski/multiwoz
- MultiWOZ HF 包装：https://huggingface.co/datasets/ConvLab/multiwoz21
- Twitter/Kaggle 页：https://www.kaggle.com/datasets/thoughtvector/customer-support-on-twitter
- Ask Ubuntu dump 元数据：https://archive.org/metadata/stackexchange
- 容量原文：`research/wordpress-native-zero-cost` @ `45fb5b4`，`docs/research/2026-10-26-wp-native-kb-capacity.md`
