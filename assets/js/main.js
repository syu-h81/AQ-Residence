//トップページheroのスライドショーの設定
$(function() {
  $('.top-hero__bgImg__inner').slick({
      fade: true,
      autoplay: true,
      speed: 1500,
      autoplaySpeed : 3000,
      pauseOnFocus: false,
      pauseOnHover: false,
      arrows: false,
  })
});

// ギャラリーのスライドショーの設定
$(function() {
  $('.slider').slick({
    autoplay: false, // 自動再生ON
    autoplaySpeed: 3000,
    speed: 400,
    dots: false, // ドットインジケーターON
    centerMode: true, // 両サイドに前後のスライド表示
    centerPadding: '0px', // 左右のスライドのpadding
    slidesToShow: 3, // 一度に表示するスライド数
  });
});

//ハンバーガーメニューの実装
$(function() {
  $('.sp-header-humburger, .header-menu-btn, .sp-menu-nav__close').on('click', function() {
    $('.sp-menu-nav').fadeToggle();
    $('.sp-header-humburger__bar').toggleClass('close');
  });
});

