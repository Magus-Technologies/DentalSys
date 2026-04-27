 </div><!-- .pb -->
</div><!-- .mw -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
function sbT(){document.getElementById('sb').classList.toggle('open');document.getElementById('sbOv').classList.toggle('open')}
Chart.defaults.color='#A0B0C0';Chart.defaults.borderColor='rgba(0,212,238,.08)';
// Activar tab por URL hash
document.addEventListener('DOMContentLoaded',()=>{
 if(location.hash){const el=document.querySelector(`[data-bs-target="${location.hash}"]`)||document.querySelector(`[href="${location.hash}"]`);if(el&&el.classList.contains('nav-link'))new bootstrap.Tab(el).show();}
});
</script>
<?php if(isset($xscript)) echo $xscript; ?>
</body></html>
