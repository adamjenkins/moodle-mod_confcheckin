<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Language strings for mod_confcheckin (Japanese).
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
$string['addpromocode'] = 'プロモコードを追加';
$string['addtickettype'] = 'チケット種別を追加';
$string['alreadycheckedin'] = '既にチェックイン済みです';
$string['autogrant'] = '自動付与';
$string['autogrant_help'] = 'ユーザーが特定のコースグループに参加した瞬間、または特定の登録方法で登録された瞬間に、この種別の無料チケットを自動的に発行します。「ボランティア」グループへのチケット提供や、特定のセルフ登録キーでの提供などに便利です。以下の2つのうち、どちらか一方のみを選択してください。この設定を保存すると、今後参加・登録するユーザーだけでなく、現在既にグループに所属している、または登録されている全員にも直ちにチケットが付与されます。後でユーザーがグループを抜けたり登録を解除されたりしても、そのチケットはそのまま残ります——付与条件が失われたチケットの一覧は「孤立チケット」で確認でき、そこから手動で取り消すことができます。';
$string['autograntenrol'] = '登録方法による自動付与';
$string['autograntenrolvalue'] = '登録方法: {$a}';
$string['autograntgroup'] = 'グループによる自動付与';
$string['autograntgroupvalue'] = 'グループ: {$a}';
$string['availabletickettypes'] = '購入可能なチケット種別';
$string['badge'] = 'バッジ';
$string['buyticket'] = 'チケットを購入';
$string['cameraerror'] = 'カメラにアクセスできませんでした。下のテキスト欄からでも引き続きチェックインを記録できます。';
$string['capacity'] = '定員';
$string['capacity_help'] = 'この種別のチケットを発行できる最大数です。上限を設けない場合は空欄にしてください。';
$string['certificate'] = '修了証';
$string['checkedin'] = 'チェックインしました';
$string['confcheckin:addinstance'] = 'Conference Check-in アクティビティを新規追加する';
$string['confcheckin:downloadbadges'] = '参加者全員のバッジ/チケットPDFを一括ダウンロードする';
$string['confcheckin:managetemplates'] = 'バッジ・チケット・領収書・修了証のテンプレートを編集する';
$string['confcheckin:managetickettypes'] = 'チケット種別とプロモコードを管理する';
$string['confcheckin:purchase'] = 'チケットを購入・取得する';
$string['confcheckin:scancheckin'] = 'QRスキャナーを使ってチェックインを記録する';
$string['confcheckin:viewowncertificate'] = '自分の参加証明書をダウンロードする';
$string['confirmdeletepromocode'] = 'プロモコード「{$a}」を削除しますか？ 既にこのコードで発行されたチケットには影響しません。';
$string['confirmdeletetickettype'] = 'チケット種別「{$a}」を削除しますか？ 既に発行されたチケットには影響しません。';
$string['confirmrevoketicket'] = '{$a}さんのチケットを取り消しますか？ この操作は完全に削除され（記録済みのチェックインも含む）、そのQRコードは使用できなくなります。';
$string['confprogramcmid'] = 'Conference Program アクティビティ';
$string['confprogramcmid_help'] = '発表者限定チケットの対象資格を判定する際に参照する Conference Program アクティビティです。あるユーザーが（手動入力の共同発表者ではなく）Moodle アカウントとして登録された発表者として、このアクティビティで採択された応募に1件でも名前が載っていれば、「発表者限定」チケット種別の対象資格があるとみなされます。任意項目です——未設定の場合、「発表者限定」チケット種別は誰も購入・取得できません。';
$string['currency'] = '通貨';
$string['delimiterend'] = '閉じ区切り記号';
$string['delimiterend_desc'] = 'テンプレートのプレースホルダーの閉じ区切り記号です。例えば `[[fullname]]` の `]]` の部分です。テンプレートを作成した後でこれを変更する場合、既存のテンプレートを新しい区切り記号に合わせて更新する必要があります——古い区切り記号で書かれた既存のプレースホルダーは認識されなくなります。';
$string['delimiterstart'] = '開き区切り記号';
$string['delimiterstart_desc'] = 'テンプレートのプレースホルダーの開き区切り記号です。例えば `[[fullname]]` の `[[` の部分です。';
$string['downloadall'] = 'すべての{$a}をダウンロード';
$string['editpromocode'] = 'プロモコードを編集';
$string['edittickettype'] = 'チケット種別を編集';
$string['error:autograntexclusive'] = 'グループによる自動付与か登録方法による自動付与のどちらか一方のみを選択してください。両方は選択できません。';
$string['error:certificatenotready'] = 'このチケットはまだチェックインされていないため、証明書はまだ利用できません。';
$string['error:invalidautogrant'] = 'そのグループまたは登録方法はこのコースに属していません。';
$string['error:invalidcapacity'] = '定員は1以上の整数を入力するか、無制限にする場合は空欄にしてください。';
$string['error:invalidconfprogramcmid'] = 'それはこのコース内の Conference Program アクティビティではありません。';
$string['error:invalidcurrency'] = '有効な通貨を選択してください。';
$string['error:invalidmaxuses'] = '最大利用回数は1以上の整数を入力するか、無制限にする場合は空欄にしてください。';
$string['error:invalidprice'] = '0以上の有効な価格を入力してください（例: 0.00 や 49.99）。';
$string['error:invalidpromocode'] = 'そのプロモコードは無効です。';
$string['error:invalidqrtoken'] = 'そのQRコード（チケットトークン）は認識されませんでした。';
$string['error:invalidtemplatetype'] = '認識できないテンプレート種別です。';
$string['error:invalidticket'] = 'そのチケットが見つかりませんでした。';
$string['error:invalidtickettype'] = 'そのチケット種別が見つかりませんでした。';
$string['error:nopaymentaccount'] = 'このアクティビティにはまだ決済アカウントが設定されていないため、有料のチケット種別は現在購入できません。コースの主催者にお問い合わせください。';
$string['error:noreceiptforfree'] = '無料またはプロモコードによるチケットには領収書は発行されません（支払いが発生していないため）。';
$string['error:notpresenteronly'] = 'そのチケット種別は対象資格のある発表者のみが購入できます。';
$string['error:promocodeexhausted'] = 'そのプロモコードは既に利用可能回数の上限まで使用されています。';
$string['error:promocodeexpired'] = 'そのプロモコードは有効期限が切れています。';
$string['error:promocodenotunique'] = 'そのコードはこのアクティビティで既に使用されています。別のコードを選んでください。';
$string['error:qrtokenwrongevent'] = 'そのチケットはこのイベント用ではありません。';
$string['error:tickettypenotfree'] = 'そのチケット種別は無料ではありません。';
$string['error:tickettypesoldout'] = 'そのチケット種別は完売しました。';
$string['error:validtobeforevalidfrom'] = '「有効期限」は「有効開始日」より前にはできません。';
$string['free'] = '無料';
$string['getfreeticket'] = '無料チケットを取得';
$string['grantsticketype'] = '付与されるチケット種別';
$string['managepromocodes'] = 'プロモコードの管理';
$string['managetemplates'] = 'バッジ・チケット・領収書・修了証テンプレートの管理';
$string['managetickettypes'] = 'チケット種別の管理';
$string['maxuses'] = '最大利用回数';
$string['maxuses_help'] = 'このコードを合計で利用できる最大回数です。上限を設けない場合は空欄にしてください。';
$string['modulename'] = 'Conference Check-in';
$string['modulename_help'] = 'Conference Check-in アクティビティは、カンファレンスのチケットを販売・発行し、QRコード付きバッジを生成し、QRスキャナーによる参加記録を行います。チケット種別は（Conference Program アクティビティとの連携により）採択された応募の発表者に限定したり、プロモコードで販売したり、主催者が編集したバッジ・チケット・領収書・修了証のテンプレートを設定したりできます。参加者はチェックイン後に参加証明書をダウンロードできます。';
$string['modulenameplural'] = 'Conference Check-in';
$string['mytickets'] = 'あなたのチケット';
$string['noinstances'] = 'このコースにはまだ Conference Check-in アクティビティがありません。';
$string['noorphanedtickets'] = '孤立チケットは見つかりませんでした。';
$string['nopromocodes'] = 'まだプロモコードが追加されていません。';
$string['notickets'] = 'まだチケットが発行されていません。';
$string['notickettypes'] = 'まだチケット種別が追加されていません。';
$string['notickettypesyet'] = 'まだチケット種別が追加されていません。先に追加してください。';
$string['origin'] = '取得方法';
$string['origin:free'] = '無料';
$string['origin:grant'] = '自動付与';
$string['origin:promo'] = 'プロモコード';
$string['origin:purchase'] = '購入';
$string['orphanedreason'] = '孤立理由';
$string['orphanedreason:enrol'] = 'リンクされた登録方法での登録が解除されています';
$string['orphanedreason:group'] = 'リンクされたグループのメンバーではなくなっています';
$string['orphanedtickets'] = '孤立チケット';
$string['orphanedtickets_help'] = 'リンクされたグループまたは登録方法を通じて自動付与されたが（チケット種別の追加・編集時の「自動付与」を参照）、保持者がそのグループのメンバーでなくなった、またはその方法で登録されなくなったチケットです。この状態になっても、チケットは自動的には取り消されません——ここで個別に確認し、必要であれば手動で取り消してください。';
$string['paymentaccountid'] = '決済アカウント';
$string['paymentaccountid_help'] = 'このインスタンスの有料チケット種別の支払い先となる決済アカウントです。価格が0円より大きいチケット種別を販売する場合にのみ必要です。無料チケットやプロモコードによるチケットではこの設定は使用されません。';
$string['placeholderheading'] = 'テンプレートのプレースホルダー';
$string['placeholderheading_desc'] = 'バッジ・チケット・領収書・修了証のテンプレート内で、プレースホルダー項目（参加者名、QRコードなど）を示すために主催者が使用する区切り記号を設定します。';
$string['pluginadministration'] = 'Conference Check-in の管理';
$string['pluginname'] = 'Conference Check-in';
$string['presenteronly'] = '発表者限定';
$string['presenteronly_help'] = 'このチケット種別を、連携している Conference Program アクティビティで採択された応募に、（Moodle アカウントとして登録された発表者として）1件以上名前が載っているユーザーに限定します。';
$string['price'] = '価格';
$string['price_help'] = 'チケットの価格を小数で入力します（例: 49.99）。無料のチケット種別にする場合は 0.00 を指定してください。この場合、決済システムを経由せず直接発行されます。';
$string['privacy:metadata'] = 'Conference Check-in プラグインは、発行されたチケットと記録されたチェックインに関する個人情報を、自身のテーブルに保存します。チケット種別・テンプレート・プロモコードの設定には個人情報は含まれません。決済金額・ステータスはこのプラグインでは一切保存されず、core_payment 自身のテーブルに保存されます。';
$string['privacy:metadata:confcheckin_checkin'] = '発行済みチケットに対して記録されたチェックインイベント。';
$string['privacy:metadata:confcheckin_checkin:scannedby'] = 'このチェックインを記録したQRスキャンを行ったユーザーのID。誰がチェックインを行ったかの運用上の記録として保持され、そのスタッフ自身が後から自分の個人データの削除を要求した場合でも、削除・匿名化されません。';
$string['privacy:metadata:confcheckin_checkin:timecreated'] = 'チェックインが記録された日時。';
$string['privacy:metadata:confcheckin_ticket'] = '発行済みチケット。Conference Check-in インスタンスごと、参加者ごとに1行。';
$string['privacy:metadata:confcheckin_ticket:origin'] = 'チケットの取得方法（購入・無料・プロモコード）。';
$string['privacy:metadata:confcheckin_ticket:qrtoken'] = 'このチケットのQRコードを識別する一意のトークン。';
$string['privacy:metadata:confcheckin_ticket:timecreated'] = 'チケットが発行された日時。';
$string['privacy:metadata:confcheckin_ticket:timemodified'] = 'チケットが最後に更新された日時。';
$string['privacy:metadata:confcheckin_ticket:userid'] = 'チケットが発行されたユーザーのID。';
$string['promocode'] = 'プロモコード';
$string['promocodeadded'] = 'プロモコードを追加しました。';
$string['promocodedeleted'] = 'プロモコードを削除しました。';
$string['promocodeupdated'] = 'プロモコードを更新しました。';
$string['purchased'] = '日付';
$string['purchasedescription'] = 'チケット: {$a}';
$string['purchaseticket'] = 'チケットを購入・取得する';
$string['receipt'] = '領収書';
$string['redeem'] = '利用する';
$string['redeempromocode'] = 'プロモコードをお持ちですか？';
$string['revoke'] = '取り消す';
$string['scaffoldnotice'] = 'チケット購入はまだ利用できません。このアクティビティのいずれの部分を閲覧できる権限もまだありません。';
$string['scancheckin'] = 'チェックインをスキャン';
$string['scancheckin_help'] = 'チケットのQRトークンを入力または貼り付けてチェックインを記録するか、USB/Bluetoothバーコードスキャナーを使用してください（スキャンした値がキーボード入力のように直接この欄へ入力されます）。お使いのブラウザが対応していれば「カメラでスキャン」オプションも表示されます。';
$string['scanning'] = '確認中...';
$string['scanqrtoken'] = 'QRコード／チケットトークン';
$string['scanqrtokensubmit'] = 'チェックイン';
$string['scanwithcamera'] = 'カメラでスキャン';
$string['soldout'] = '完売';
$string['sortorder'] = '表示順';
$string['templatecontent'] = 'テンプレート内容';
$string['templatecontent_help'] = 'このフォームの上に表示されている利用可能なプレースホルダーの一覧をご覧ください。認識されないプレースホルダーは、PDF生成時に単純に削除されます。';
$string['templateplaceholders'] = '利用可能なプレースホルダー: {$a->placeholders}、および対象資格のある発表者の場合のみ {$a->presenterplaceholders}。';
$string['templatesaved'] = 'テンプレートを保存しました。';
$string['ticket'] = 'チケット';
$string['ticketissued'] = 'チケットが発行されました。';
$string['ticketrevoked'] = 'チケットを取り消しました。';
$string['tickettypeadded'] = 'チケット種別を追加しました。';
$string['tickettypedeleted'] = 'チケット種別を削除しました。';
$string['tickettypename'] = 'チケット種別名';
$string['tickettypeupdated'] = 'チケット種別を更新しました。';
$string['timeexpires'] = '有効期限';
$string['timeexpires_help'] = 'このコードが利用できなくなる日付です。無期限にする場合は空欄にしてください。';
$string['unlimited'] = '無制限';
$string['uses'] = '利用回数';
$string['validfrom'] = '有効開始日';
$string['validfrom_help'] = 'このチケット種別で入場が可能になる日付です。現段階では記録目的のみで、チェックイン時には強制されません。';
$string['validfromdate'] = '{$a}から有効。';
$string['validto'] = '有効終了日';
$string['validto_help'] = 'このチケット種別で入場が可能な最終日です。現段階では記録目的のみで、チェックイン時には強制されません。';
$string['validtodate'] = '{$a}まで有効。';
$string['visible'] = '表示';
$string['visible_help'] = 'このチケット種別を購入ページに表示するかどうかです。非表示にしてもチケット種別自体は削除されず、既に発行済みのチケットにも影響しません。';
