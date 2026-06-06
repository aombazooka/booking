/* แผนที่ปักหมุด (Leaflet + OpenStreetMap, ไม่ต้องใช้ API key)
 * ต้องมี element: #locationInput (ช่องกรอก), #mapBox (กล่องแผนที่), #toggleMap (ปุ่มเปิด/ปิด), #useMyLoc (ออปชัน)
 * ปักหมุดแล้วจะใส่ลิงก์ Google Maps (https://www.google.com/maps?q=lat,lng) ลงในช่อง location
 */
(function () {
  const input  = document.getElementById('locationInput');
  const box    = document.getElementById('mapBox');
  const toggle = document.getElementById('toggleMap');
  const useMy  = document.getElementById('useMyLoc');
  if (!input || !box || !toggle || typeof L === 'undefined') return;

  const DEFAULT = [9.1382, 99.3215]; // สุราษฎร์ธานี
  let map, marker, inited = false;

  const gmaps = (lat, lng) => 'https://www.google.com/maps?q=' + lat.toFixed(6) + ',' + lng.toFixed(6);
  const setVal = (lat, lng) => { input.value = gmaps(lat, lng); };

  function parseLatLng(v) {
    if (!v) return null;
    const m = v.match(/[?&]q=(-?\d+\.?\d*),\s*(-?\d+\.?\d*)/) || v.match(/@(-?\d+\.?\d*),(-?\d+\.?\d*)/);
    return m ? [parseFloat(m[1]), parseFloat(m[2])] : null;
  }

  function ensure(center) {
    if (inited) return;
    map = L.map(box).setView(center || DEFAULT, 14);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19, attribution: '&copy; OpenStreetMap'
    }).addTo(map);
    marker = L.marker(center || DEFAULT, { draggable: true }).addTo(map);
    marker.on('dragend', () => { const p = marker.getLatLng(); setVal(p.lat, p.lng); });
    map.on('click', (e) => { marker.setLatLng(e.latlng); setVal(e.latlng.lat, e.latlng.lng); });

    // แถบค้นหาสถานที่ (Nominatim/OpenStreetMap — ฟรี)
    const bar = document.createElement('div');
    bar.style.cssText = 'display:flex;gap:6px;margin-bottom:8px;';
    bar.innerHTML = '<input type="text" class="form-control form-control-sm" placeholder="ค้นหาสถานที่ เช่น เซ็นทรัล สุราษฎร์ธานี"><button type="button" class="btn btn-sm btn-dark" style="white-space:nowrap;">ค้นหา</button>';
    box.parentNode.insertBefore(bar, box);
    const sInput = bar.querySelector('input'), sBtn = bar.querySelector('button');
    async function doSearch() {
      const q = sInput.value.trim();
      if (!q) return;
      sBtn.disabled = true; sBtn.textContent = '...';
      try {
        const r = await fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=th&accept-language=th&q=' + encodeURIComponent(q));
        const arr = await r.json();
        if (arr && arr[0]) {
          const lat = parseFloat(arr[0].lat), lng = parseFloat(arr[0].lon);
          marker.setLatLng([lat, lng]); map.setView([lat, lng], 16); setVal(lat, lng);
        } else { alert('ไม่พบสถานที่ที่ค้นหา ลองพิมพ์ใหม่หรือปักหมุดเอง'); }
      } catch (e) { alert('ค้นหาไม่สำเร็จ'); }
      finally { sBtn.disabled = false; sBtn.textContent = 'ค้นหา'; }
    }
    sBtn.addEventListener('click', doSearch);
    sInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); doSearch(); } });

    inited = true;
    setTimeout(() => map.invalidateSize(), 200);
  }

  toggle.addEventListener('click', () => {
    const nowHidden = box.classList.toggle('d-none');
    if (!nowHidden) {
      const ll = parseLatLng(input.value);
      ensure(ll);
      if (ll && marker) { marker.setLatLng(ll); map.setView(ll, 15); }
      setTimeout(() => map.invalidateSize(), 150);
    }
  });

  if (useMy) useMy.addEventListener('click', () => {
    if (!navigator.geolocation) { alert('อุปกรณ์นี้ไม่รองรับการระบุตำแหน่ง'); return; }
    navigator.geolocation.getCurrentPosition((pos) => {
      const lat = pos.coords.latitude, lng = pos.coords.longitude;
      box.classList.remove('d-none');
      ensure([lat, lng]);
      marker.setLatLng([lat, lng]);
      map.setView([lat, lng], 16);
      setVal(lat, lng);
      setTimeout(() => map.invalidateSize(), 150);
    }, () => alert('ขอตำแหน่งไม่สำเร็จ — โปรดอนุญาตการเข้าถึงตำแหน่งในเบราว์เซอร์'));
  });
})();
