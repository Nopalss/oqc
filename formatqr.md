    function parseBarcode(code) {
        let obj = {};
        let parts = code.split('|');

        parts.forEach(p => {
            if (p.startsWith('Z1')) obj.Z1 = p.substring(2);
            if (p.startsWith('Z2')) obj.Z2 = p.substring(2);
            if (p.startsWith('Z3')) obj.Z3 = parseInt(p.substring(2)) || 1;
            if (p.startsWith('Z4')) obj.Z4 = p.substring(2);
            if (p.startsWith('Z5')) obj.Z5 = p.substring(2);
        });

        return obj;
    }

$part  = $_POST['Z1'] ?? '';
$lot   = $_POST['Z2'] ?? '';
jadi nanti contoh barcode nya itu 
Z1157211701|Z206426817D010000|Z3Cavity|Z4line