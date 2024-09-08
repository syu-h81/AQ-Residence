// ギャラリーのスライドショーの設定
$(function() {
  $('.slider').slick({
    autoplay: true, // 自動再生ON
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
  $('.sp-header-humburger').on('click', function() {
    $('.sp-menu-nav').fadeToggle();
    $('.sp-header-humburger__bar').toggleClass('close');
  });
});