document.addEventListener('DOMContentLoaded', function () {
    const selectors = document.querySelectorAll('.address-selector');
    if (!selectors.length) return;

    fetch(window.BASE_URL ? window.BASE_URL + 'assets/data/dingalan_address.json' : '/assets/data/dingalan_address.json')
        .then(res => res.json())
        .then(data => {
            selectors.forEach(initSelector.bind(null, data));
        })
        .catch(err => {
            console.error('Failed to load address data', err);
        });

    function initSelector(data, container) {
        const muniSel = container.querySelector('select[name="address_municipality"]');
        const brgySel = container.querySelector('select[name="address_barangay"]');
        const streetSel = container.querySelector('select[name="address_street"]');
        const fullAddressField = container.querySelector('input[name="address_full"]');

        const selectedMuni = container.dataset.selectedMunicipality || '';
        const selectedBrgy = container.dataset.selectedBarangay || '';
        const selectedStreet = container.dataset.selectedStreet || '';
        const municipalities = Array.isArray(data.municipalities) ? data.municipalities : [];

        function populateMunicipalities() {
            if (!muniSel) return;
            muniSel.innerHTML = '<option value="">Select municipality</option><option value="Other">Other</option>';

            municipalities.forEach(m => {
                const opt = document.createElement('option');
                opt.value = m.name;
                opt.textContent = m.name;
                if (m.name === selectedMuni) opt.selected = true;
                muniSel.appendChild(opt);
            });

            if (selectedMuni) {
                muniSel.value = selectedMuni;
            }
        }

        function toggleOtherAddressMode() {
            const isOther = muniSel && muniSel.value === 'Other';
            const brgyWrapper = brgySel ? brgySel.closest('.form-group') : null;
            const streetWrapper = streetSel ? streetSel.closest('.form-group') : null;
            const fullAddressWrapper = container.querySelector('.address-full-wrapper');

            if (brgyWrapper) brgyWrapper.style.display = isOther ? 'none' : 'block';
            if (streetWrapper) streetWrapper.style.display = isOther ? 'none' : 'block';
            if (fullAddressWrapper) {
                fullAddressWrapper.style.display = isOther ? 'block' : 'none';
                if (!isOther && fullAddressField) {
                    fullAddressField.value = '';
                }
            }
        }

        function populateBarangays() {
            if (!brgySel || !streetSel) return;
            const muni = muniSel ? muniSel.value : '';
            brgySel.innerHTML = '<option value="">Select barangay</option>';
            streetSel.innerHTML = '<option value="">Select street</option>';
            if (!muni || muni === 'Other') {
                toggleOtherAddressMode();
                return;
            }
            toggleOtherAddressMode();
            const mun = municipalities.find(x => x.name === muni);
            if (!mun) return;
            mun.barangays.forEach(b => {
                const opt = document.createElement('option');
                opt.value = b.name;
                opt.textContent = b.name;
                if (b.name === selectedBrgy) opt.selected = true;
                brgySel.appendChild(opt);
            });
            if (selectedBrgy) populateStreets();
        }

        function populateStreets() {
            if (!brgySel || !streetSel) return;
            const muni = muniSel ? muniSel.value : '';
            const brgy = brgySel.value;
            streetSel.innerHTML = '<option value="">Select street</option>';
            if (!muni || !brgy) return;
            const mun = municipalities.find(x => x.name === muni);
            if (!mun) return;
            const b = mun.barangays.find(x => x.name === brgy);
            if (!b) return;
            b.streets.forEach(s => {
                const opt = document.createElement('option');
                opt.value = s;
                opt.textContent = s;
                if (s === selectedStreet) opt.selected = true;
                streetSel.appendChild(opt);
            });
        }

        populateMunicipalities();

        if (muniSel) {
            muniSel.addEventListener('change', function () {
                if (brgySel) {
                    brgySel.innerHTML = '<option value="">Select barangay</option>';
                }
                if (streetSel) {
                    streetSel.innerHTML = '<option value="">Select street</option>';
                }
                populateBarangays();
            });
        }

        if (brgySel) {
            brgySel.addEventListener('change', function () {
                if (streetSel) {
                    streetSel.innerHTML = '<option value="">Select street</option>';
                }
                populateStreets();
            });
        }

        if (selectedMuni) {
            populateBarangays();
        } else {
            toggleOtherAddressMode();
        }
    }
});
