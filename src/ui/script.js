// Minimal interactivity: make metric cards clickable for quick highlights
document.addEventListener('DOMContentLoaded', ()=>{
  const metrics = document.querySelectorAll('.metric');
  metrics.forEach(m=>{
    m.addEventListener('click', ()=>{
      metrics.forEach(x=>x.classList.remove('active'));
      m.classList.add('active');
    });
  });
});
