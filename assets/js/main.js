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