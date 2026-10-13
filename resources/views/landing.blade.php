<!doctype html>
<html lang="ar" dir="rtl">
<!-- CR Delivery landing page -->
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>CR Delivery — اطلب من أي مكان في مصر</title>
<meta name="description" content="CR Delivery تطبيق توصيل في مصر. اطلب من المطاعم والسوبر ماركت والصيدليات في سلة واحدة، وتابع المندوب على الخريطة لحد باب بيتك.">
<meta property="og:title" content="CR Delivery — كل الأماكن في جيبك">
<meta property="og:description" content="اطلب من أكثر من مكان في طلب واحد، وتابع المندوب لحظة بلحظة.">
<meta name="theme-color" content="#FFC61A">
<meta property="og:image" content="{{ asset('images/landing/screen-places.webp') }}">
<link rel="icon" type="image/png" href="{{ asset('images/landing/logo.png') }}">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Baloo+Bhaijaan+2:wght@600;800&family=IBM+Plex+Sans+Arabic:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root{
  --yellow:#FFC61A; --yellow-deep:#F2A900; --ink:#17171A; --ink-2:#232328; --ink-3:#2F2F36;
  --paper:#FFFFFF; --muted:#A3A3AD; --line:rgba(255,255,255,.12);
  --display:"Baloo Bhaijaan 2", "IBM Plex Sans Arabic", system-ui, sans-serif;
  --body:"IBM Plex Sans Arabic", "Segoe UI", Tahoma, system-ui, sans-serif;
  --wrap:1180px;
  box-sizing:border-box;
  padding-top:env(safe-area-inset-top,0px); padding-bottom:env(safe-area-inset-bottom,0px);
}
*,*::before,*::after{box-sizing:inherit}
html{scroll-behavior:smooth; scroll-padding-top:calc(72px + env(safe-area-inset-top,0px))}
body{margin:0; background:var(--ink); color:var(--paper); font-family:var(--body); font-size:17px; line-height:1.75; -webkit-font-smoothing:antialiased}
img{max-width:100%; display:block}
a{color:inherit}
:focus-visible{outline:3px solid var(--yellow); outline-offset:3px; border-radius:6px}
.wrap{max-width:var(--wrap); margin-inline:auto; padding-inline:24px}
h1,h2,h3{font-family:var(--display); font-weight:800; line-height:1.15; margin:0}

/* header */
.top{position:sticky; top:0; z-index:20; background:var(--ink); border-bottom:1px solid var(--line)}
.top .wrap{display:flex; align-items:center; gap:28px; height:72px}
.top .logo img{height:44px; width:auto}
.top nav{display:flex; gap:24px; margin-inline-start:auto; font-weight:500; font-size:15px}
.top nav a{text-decoration:none; color:#E4E4E8}
.top nav a:hover{color:var(--yellow)}
.btn{display:inline-flex; align-items:center; justify-content:center; gap:10px; font-family:var(--body); font-weight:600; font-size:16px; text-decoration:none; border-radius:14px; padding:12px 22px; transition:transform .15s ease, background-color .15s ease}
.btn:active{transform:scale(.97)}
.btn-yellow{background:var(--yellow); color:var(--ink)}
.btn-yellow:hover{background:#FFD24D}
.btn-ink{background:var(--ink); color:var(--paper)}
.btn-ink:hover{background:#000}
.btn small{display:block; font-size:11px; font-weight:500; opacity:.75; line-height:1.2}
.btn span{line-height:1.25; text-align:start}

/* hero */
.hero{position:relative; overflow:hidden; background:var(--yellow); color:var(--ink)}
.hero::before{ /* speed lines from the logo */
  content:""; position:absolute; inset:0; pointer-events:none;
  background:repeating-linear-gradient(-14deg, transparent 0 46px, rgba(242,169,0,.45) 46px 58px, transparent 58px 130px);
  mask-image:linear-gradient(to left, transparent 30%, #000 100%);
  -webkit-mask-image:linear-gradient(to left, transparent 30%, #000 100%);
}
.hero .wrap{position:relative; display:grid; grid-template-columns:1.05fr 1fr; align-items:center; gap:40px; padding-block:72px 0}
.hero-copy{padding-bottom:72px}
.hero h1{font-size:clamp(46px,7.2vw,92px); letter-spacing:-.5px}
.hero h1 .line{display:block}
.hero h1 .box{display:inline-block; background:var(--ink); color:var(--yellow); padding:.02em .28em .12em; border-radius:14px; margin-top:.12em; transform:rotate(-1.5deg)}
.hero p.lede{font-size:clamp(18px,2vw,21px); max-width:30ch; margin:26px 0 32px; font-weight:500}
.stores{display:flex; flex-wrap:wrap; gap:12px}
.hero-note{margin-top:18px; font-size:14px; opacity:.8}

.phones{position:relative; height:560px; align-self:end}
.phone{position:absolute; bottom:-40px; width:250px; border-radius:34px; overflow:hidden; box-shadow:0 30px 60px -20px rgba(23,23,26,.55); border:6px solid var(--ink)}
.phone img{width:100%; height:auto}
.phone.p1{inset-inline-start:50%; translate:50% 0; z-index:3}
.phone.p2{inset-inline-start:2%; rotate:7deg; bottom:-80px; z-index:2}
.phone.p3{inset-inline-end:2%; rotate:-7deg; bottom:-80px; z-index:1}
@media (prefers-reduced-motion:no-preference){
  .phone{animation:rise .9s cubic-bezier(.2,.8,.2,1) both}
  .phone.p1{animation-delay:.05s}.phone.p2{animation-delay:.18s}.phone.p3{animation-delay:.3s}
  @keyframes rise{from{opacity:0; transform:translateY(80px)}to{opacity:1; transform:none}}
}

/* section heads */
.sec{padding-block:104px}
.sec-head{max-width:640px; margin-bottom:56px}
.sec-head h2{font-size:clamp(34px,4.6vw,56px)}
.sec-head p{color:var(--muted); font-size:19px; margin:14px 0 0}

/* features */
.groups{display:grid; grid-template-columns:repeat(3,1fr); gap:0}
.group{padding-inline:32px; border-inline-start:1px solid var(--line)}
.group:first-child{padding-inline-start:0; border:0}
.group h3{font-size:30px; color:var(--yellow); margin-bottom:12px}
.feat{padding-block:20px; border-top:1px solid var(--line)}
.feat:first-of-type{border-top:0}
.feat strong{display:block; font-size:18px; font-weight:600; margin-bottom:4px}
.feat p{margin:0; color:var(--muted); font-size:16px}
.pay{display:flex; flex-wrap:wrap; gap:8px; margin-top:12px}
.pay span{background:var(--ink-3); border-radius:999px; padding:4px 14px; font-size:14px; font-weight:500}
.after{margin-top:56px; display:flex; align-items:center; gap:18px; background:var(--ink-2); border-radius:20px; padding:22px 28px}
.after .ico{flex:none; width:48px; height:48px; border-radius:14px; background:var(--yellow); color:var(--ink); display:grid; place-items:center}
.after p{margin:0; color:#D6D6DC}
.after strong{color:var(--paper)}

/* steps */
.steps-sec{background:#FFF6D6; color:var(--ink)}
.steps-sec .sec-head p{color:#5A5444}
.steps{list-style:none; margin:0; padding:0; display:grid; grid-template-columns:repeat(4,1fr); gap:28px; counter-reset:s}
.steps li{counter-increment:s}
.steps .shot{border-radius:26px; overflow:hidden; border:5px solid var(--ink); background:var(--ink); aspect-ratio:344/755}
.steps .shot img{width:100%; height:100%; object-fit:cover}
.steps h3{font-size:24px; margin-top:22px; display:flex; align-items:baseline; gap:10px}
.steps h3::before{content:counter(s); font-size:20px; flex:none; width:34px; height:34px; border-radius:50%; background:var(--yellow); display:inline-grid; place-items:center; line-height:1}
.steps p{margin:6px 0 0; color:#5A5444; font-size:16px}

/* partners */
.partners .cols{display:grid; grid-template-columns:1fr 1fr; gap:28px}
.pcard{background:var(--ink-2); border-radius:28px; padding:40px}
.pcard h3{font-size:32px; margin-bottom:6px}
.pcard > p{color:var(--muted); margin:0 0 24px}
.pcard ul{list-style:none; margin:0; padding:0}
.pcard li{position:relative; padding:12px 30px 12px 0; border-top:1px solid var(--line)}
.pcard li::before{content:""; position:absolute; right:4px; top:24px; width:10px; height:10px; border-radius:3px; background:var(--yellow); rotate:45deg}
.privacy{margin-top:24px; border:1.5px solid var(--yellow); border-radius:18px; padding:16px 20px; font-size:15.5px; color:#EDEDF0}
.privacy b{color:var(--yellow)}
.join{margin-top:40px; display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:20px}
.join p{margin:0; font-size:19px}

/* download */
.dl{background:var(--yellow); color:var(--ink); position:relative; overflow:hidden}
.dl .wrap{display:grid; grid-template-columns:1.2fr 1fr; gap:40px; align-items:center; padding-block:88px}
.dl h2{font-size:clamp(38px,5.2vw,68px)}
.dl p{font-size:19px; font-weight:500; margin:16px 0 28px; max-width:34ch}
.qrs{display:flex; gap:20px; justify-content:flex-end}
.qr{background:var(--paper); border-radius:22px; padding:16px; text-align:center; width:190px; box-shadow:0 18px 40px -18px rgba(23,23,26,.5)}
.qr{text-decoration:none; color:var(--ink); transition:transform .15s ease}
.qr:hover{transform:translateY(-3px)}
.qr .code{aspect-ratio:1; display:grid; place-items:center}
.qr .code img{width:100%; height:100%; object-fit:contain}
.qr b{display:block; margin-top:10px; font-size:15px}

/* footer */
footer{padding-block:48px; border-top:1px solid var(--line)}
footer .wrap{display:flex; flex-wrap:wrap; align-items:center; gap:24px 40px}
footer img{height:40px; width:auto}
footer nav{display:flex; flex-wrap:wrap; gap:22px; font-size:15px}
footer nav a{text-decoration:none; color:#CFCFD6}
footer nav a:hover{color:var(--yellow)}
footer small{margin-inline-start:auto; color:var(--muted)}

/* responsive */
@media (max-width:980px){
  .top nav{display:none}
  .top .btn{margin-inline-start:auto}
  .hero .wrap{grid-template-columns:1fr; padding-top:48px}
  .hero-copy{padding-bottom:0}
  .phones{height:430px}
  .phone{width:200px}
  .groups{grid-template-columns:1fr; gap:40px}
  .group{padding:0; border:0}
  .steps{grid-template-columns:repeat(2,1fr)}
  .partners .cols{grid-template-columns:1fr}
  .dl .wrap{grid-template-columns:1fr}
  .qrs{justify-content:flex-start}
}
@media (max-width:560px){
  body{font-size:16px}
  .sec{padding-block:72px}
  .phones{height:360px}
  .phone{width:165px; border-width:4px; border-radius:26px}
  .steps{grid-template-columns:1fr 1fr; gap:18px}
  .steps h3{font-size:19px}
  .pcard{padding:28px 22px}
  .qr{width:calc(50% - 10px)}
  .after{flex-direction:column; align-items:flex-start}
}
</style>
</head>
<body>

<header class="top">
  <div class="wrap">
    <a class="logo" href="#" aria-label="CR Delivery — الرئيسية"><img src="{{ asset('images/landing/logo.png') }}" alt="CR Delivery"></a>
    <nav aria-label="روابط الصفحة">
      <a href="#features">المميزات</a>
      <a href="#how">إزاي تطلب</a>
      <a href="#partners">للمطاعم والمندوبين</a>
    </nav>
    <a class="btn btn-yellow" href="#download">حمّل التطبيق</a>
  </div>
</header>

<main>
  <section class="hero">
    <div class="wrap">
      <div class="hero-copy">
        <h1><span class="line">كل الأماكن</span><span class="box">في جيبك</span></h1>
        <p class="lede">مطاعم وسوبر ماركت وصيدليات وكافيهات، اطلب من أكثر من مكان في طلب واحد ويوصلك لحد الباب.</p>
        <div class="stores">
                    <a class="btn btn-ink" href="https://apps.apple.com/app/id6815852436" target="_blank" rel="noopener"><span><small>حمّله من</small>App Store</span></a>
                    <a class="btn btn-ink" href="https://play.google.com/store/apps/details?id=com.cr7delivery.cr7_delivery" target="_blank" rel="noopener"><span><small>حمّله من</small>Google Play</span></a>
        </div>
        <p class="hero-note">تقدر تتصفح المطاعم والمنيو من غير ما تعمل حساب.</p>
      </div>
      <div class="phones" aria-hidden="true">
        <div class="phone p2"><img src="{{ asset('images/landing/screen-menu.webp') }}" alt=""></div>
        <div class="phone p3"><img src="{{ asset('images/landing/screen-basket.webp') }}" alt=""></div>
        <div class="phone p1"><img src="{{ asset('images/landing/screen-places.webp') }}" alt=""></div>
      </div>
    </div>
  </section>

  <section class="sec" id="features">
    <div class="wrap">
      <div class="sec-head">
        <h2>كل اللي محتاجه في تطبيق واحد</h2>
        <p>من أول ما تختار المكان لحد ما الطلب يوصل إيدك.</p>
      </div>
      <div class="groups">
        <div class="group">
          <h3>اطلب</h3>
          <div class="feat"><strong>تصفح من غير حساب</strong><p>شوف المطاعم والأصناف والمتاجر القريبة منك قبل ما تسجل.</p></div>
          <div class="feat"><strong>سلة واحدة من أكثر من مكان</strong><p>برجر من مطعم ومشروب من مكان تاني، في طلب واحد ومندوب واحد.</p></div>
          <div class="feat"><strong>مشاوير وتوصيل</strong><p>محتاج حاجة تتنقل من مكان لمكان؟ اطلب مشوار من التطبيق.</p></div>
        </div>
        <div class="group">
          <h3>يوصلك</h3>
          <div class="feat"><strong>عناوينك محفوظة</strong><p>احفظ عناوينك وحدد مكان التوصيل بالظبط على الخريطة.</p></div>
          <div class="feat"><strong>تابع طلبك لحظة بلحظة</strong><p>اعرف طلبك وصل لفين، وشوف المندوب على الخريطة لحد ما يوصل.</p></div>
        </div>
        <div class="group">
          <h3>ادفع براحتك</h3>
          <div class="feat"><strong>كاش أو تحويل</strong><p>ادفع عند الاستلام، أو حوّل وارفع صورة الإيصال من التطبيق.</p>
            <div class="pay"><span>كاش عند الاستلام</span><span>فودافون كاش</span><span>إنستاباي</span></div>
          </div>
        </div>
      </div>
      <div class="after">
        <div class="ico" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/></svg></div>
        <p><strong>بعد ما الطلب يوصل</strong> قيّم الطلب، ولو فيه أي مشكلة ابعت شكوى بالصور وهنتابعها معاك.</p>
      </div>
    </div>
  </section>

  <section class="sec steps-sec" id="how">
    <div class="wrap">
      <div class="sec-head">
        <h2>طلبك في 4 خطوات</h2>
        <p>من غير مكالمات ولا &quot;هو وصل لفين؟&quot;.</p>
      </div>
      <ol class="steps">
        <li><div class="shot"><img src="{{ asset('images/landing/screen-places.webp') }}" alt="شاشة اختيار المكان في التطبيق" loading="lazy"></div><h3>اختار المكان</h3><p>مطعم، صيدلية، سوبر ماركت أو كافيه قريب منك.</p></li>
        <li><div class="shot"><img src="{{ asset('images/landing/screen-menu.webp') }}" alt="منيو المطعم بالصور والأسعار" loading="lazy"></div><h3>شوف المنيو</h3><p>المنيو كامل بالصور والأسعار قبل ما تطلب.</p></li>
        <li><div class="shot"><img src="{{ asset('images/landing/screen-size.webp') }}" alt="اختيار الحجم والإضافات" loading="lazy"></div><h3>حدد طلبك</h3><p>ربع؟ نص؟ كيلو؟ اختار الحجم والإضافات وتعرف السعر على طول.</p></li>
        <li><div class="shot"><img src="{{ asset('images/landing/screen-basket.webp') }}" alt="سلة فيها طلبات من أكثر من مكان" loading="lazy"></div><h3>أكّد وتابع</h3><p>أكّد السلة وتابع المندوب لحد باب البيت.</p></li>
      </ol>
    </div>
  </section>

  <section class="sec partners" id="partners">
    <div class="wrap">
      <div class="sec-head">
        <h2>للمطاعم والمندوبين</h2>
        <p>صاحب المطعم والمندوب بيدخلوا بحساب بيعمله لهم فريق التشغيل.</p>
      </div>
      <div class="cols">
        <article class="pcard">
          <h3>أصحاب المطاعم</h3>
          <p>استقبل الطلبات وجهّزها من غير ما يفوتك طلب.</p>
          <ul>
            <li>الطلبات الجديدة بتظهر لك أول بأول.</li>
            <li>جرس تنبيه أول ما يوصل طلب جديد.</li>
            <li>المندوب بيستلم منك ويوصل للعميل.</li>
          </ul>
        </article>
        <article class="pcard">
          <h3>المندوبين</h3>
          <p>شوف عروض الطلبات القريبة منك واشتغل في ورديتك.</p>
          <ul>
            <li>عروض الطلبات بتوصلك على التطبيق.</li>
            <li>استلم الطلب وسلّمه للعميل.</li>
            <li>موقعك بيتشارك أثناء الوردية عشان العميل يتابع.</li>
          </ul>
          <p class="privacy"><b>خصوصيتك محفوظة:</b> موقعك بيتشارك بس وإنت في الوردية والتطبيق مفتوح، ومش بيتجمع خالص بعد ما تخلص.</p>
        </article>
      </div>
      <div class="join">
        <p>عايز تضيف مطعمك أو تشتغل مندوب معانا؟</p>
        <!-- TODO: حط رقم واتساب أو إيميل التواصل -->
        <a class="btn btn-yellow" href="#contact">كلّمنا</a>
      </div>
    </div>
  </section>

  <section class="dl" id="download">
    <div class="wrap">
      <div>
        <h2>اطلب أول طلب النهارده</h2>
        <p>حمّل التطبيق وابدأ تتصفح على طول، الحساب بتعمله لما تيجي تطلب.</p>
        <div class="stores">
          <a class="btn btn-ink" href="https://apps.apple.com/app/id6815852436" target="_blank" rel="noopener"><span><small>حمّله من</small>App Store</span></a>
          <a class="btn btn-ink" href="https://play.google.com/store/apps/details?id=com.cr7delivery.cr7_delivery" target="_blank" rel="noopener"><span><small>حمّله من</small>Google Play</span></a>
        </div>
      </div>
      <div class="qrs">
                <a class="qr" href="https://apps.apple.com/app/id6815852436" target="_blank" rel="noopener"><div class="code"><img src="{{ asset('images/landing/qr-app-store.svg') }}" alt="QR لتحميل CR Delivery من App Store"></div><b>امسح للآيفون</b></a>
        <a class="qr" href="https://play.google.com/store/apps/details?id=com.cr7delivery.cr7_delivery" target="_blank" rel="noopener"><div class="code"><img src="{{ asset('images/landing/qr-google-play.svg') }}" alt="QR لتحميل CR Delivery من Google Play"></div><b>امسح للأندرويد</b></a>
      </div>
    </div>
  </section>
</main>

<footer id="contact">
  <div class="wrap">
    <img src="{{ asset('images/landing/logo.png') }}" alt="CR Delivery">
    <nav aria-label="روابط الموقع">
      <a href="#">سياسة الخصوصية</a>
      <a href="#">الشروط والأحكام</a>
      <a href="#">تواصل معنا</a>
    </nav>
    <small>© {{ date('Y') }} CR Delivery. كل الحقوق محفوظة.</small>
  </div>
</footer>
</body>
</html>