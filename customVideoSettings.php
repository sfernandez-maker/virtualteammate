document.addEventListener("DOMContentLoaded", function() {
  const wrapper = document.getElementById('prestoWrap');
  const openBtn = document.getElementById('fullscreenToggle');
  const closeBtn = document.getElementById('closeLightbox');

  // Create overlay element dynamically
  const overlay = document.createElement('div');
  overlay.className = 'presto-overlay';
  document.body.appendChild(overlay);

  // Style for fade-in-from-bottom
  openBtn.style.opacity = 0;
  openBtn.style.transform = 'translateY(1px)';
  openBtn.style.transition = 'opacity 0.4s ease, transform 0.4s ease';

  // Show button when hovering over wrapper
  wrapper.addEventListener('mouseenter', () => {
    openBtn.style.opacity = 1;
    openBtn.style.transform = 'translateY(0)';
  });

  // Hide button when mouse leaves
  wrapper.addEventListener('mouseleave', () => {
    openBtn.style.opacity = 0;
    openBtn.style.transform = 'translateY(1px)';
  });

  // Open fake fullscreen (80%)
  openBtn.addEventListener('click', () => {
    wrapper.classList.add('expanded');
    overlay.style.display = 'block';
  });

  // Close via X button or overlay
  closeBtn.addEventListener('click', closeLightbox);
  overlay.addEventListener('click', closeLightbox);

  // Close via ESC key
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeLightbox();
  });

  function closeLightbox() {
    wrapper.classList.remove('expanded');
    overlay.style.display = 'none';
  }
});