TEMA: transportasi dan pariwisata
Pakai metode Searching
Link ppt: https://canva.link/bfn4isx7ljc5eg8

Judul
Pencarian Jadwal Kunjungan Wisata Optimal untuk Menghindari Kepadatan Pengunjung Menggunakan Algoritma Hill Climbing

Latar Belakang / Pendahuluan
Wisatawan sering tiba di objek wisata pada jam puncak kepadatan sehingga mengurangi kenyamanan berkunjung
Setiap objek wisata memiliki pola kepadatan berbeda di setiap jam operasionalnya
Menentukan jadwal kunjungan yang tepat secara manual sangat sulit, terutama jika mengunjungi banyak POI
Paper acuan (Gavalas et al., 2014) menyebutkan bahwa time window dan time dependency adalah faktor krusial dalam perencanaan wisata yang realistis namun belum banyak diterapkan secara lokal
Dibutuhkan sistem cerdas berbasis metode searching yang mampu mencari slot waktu kunjungan terbaik secara otomatis

Rumusan masalah
Bagaimana memodelkan permasalahan penjadwalan kunjungan wisata dengan mempertimbangkan pola kepadatan pengunjung tiap POI?
Bagaimana menerapkan algoritma Hill Climbing untuk mencari jadwal kunjungan yang meminimalkan kepadatan sekaligus memaksimalkan jumlah POI yang dikunjungi?
Bagaimana performa algoritma dibandingkan dengan jadwal kunjungan tanpa optimasi?

Tujuan
Memodelkan data POI beserta pola kepadatan per jam sebagai ruang pencarian
Mengimplementasikan Hill Climbing untuk mencari kombinasi slot waktu kunjungan yang optimal
Mengevaluasi hasil berdasarkan tingkat kepadatan yang ditemui dan jumlah POI yang berhasil dikunjungi

Manfaat
Teoritis:
Penerapan metode searching AI pada permasalahan penjadwalan wisata berbasis kepadatan.
Praktis:
Membantu wisatawan mendapatkan jadwal kunjungan yang lebih nyaman dan efisien
Dapat dikembangkan menjadi fitur pada aplikasi wisata digital

Metode yang akan digunakan
Metode Searching - Hill Climb
Alur sistem:
Data POI & Kepadatan → Pemodelan Jadwal → Hill Climbing → Evaluasi
Penjelasan Tiap Tahap:
Pengumpulan Data
Data POI: nama, jam operasional, durasi kunjungan rata-rata
Data kepadatan per jam tiap POI (dari Google Maps Popular Times / survei langsung)
Pemodelan Masalah
Setiap POI memiliki skor kepadatan per slot waktu (pagi, siang, sore)
Tujuan: cari kombinasi jadwal kunjungan dengan total skor kepadatan minimum
Constraint: jam operasional POI, durasi kunjungan, jumlah hari wisata
Metode Searching — Hill Climbing

Langkah
Proses
Initial State
Jadwal awal dibuat secara acak / greedy
Generate Neighbor
Tukar slot waktu kunjungan antar POI
Evaluasi
Hitung total skor kepadatan jadwal baru
Move
Pindah ke jadwal baru jika lebih baik
Stop
Jika tidak ada tetangga yang lebih baik (local optimum)


Evaluasi
Bandingkan jadwal hasil Hill Climbing vs jadwal tanpa optimasi
Metrik: rata-rata skor kepadatan, jumlah POI terkunjungi, waktu komputasi

Referensi
Gavalas et al. (2014) — A Survey on Algorithmic Approaches for Solving Tourist Trip Design Problems
Vansteenwegen et al. (2009) — Iterated Local Search for the TOPTW. Computers & Operations Research
Vansteenwegen et al. (2011) — The Orienteering Problem: A Survey. European Journal of Operational Research
Fomin & Lingas (2002) — Approximation Algorithms for Time-Dependent Orienteering. Information Processing Letters
Garcia et al. (2013) — Integrating Public Transportation in Personalised Electronic Tourist Guides. Computers & Operations Research


FLOW WEB APP:

flowchart TD
    subgraph Input_User [Input User]
        A([Akses Fitur Penjadwalan]) --> B[/Pilih Daftar POI yang Ingin Dikunjungi/]
        B --> C[/Input Jumlah Hari Wisata/]
        C --> D[/Input Jam Mulai dan Jam Selesai Harian/]
        D --> E(Mulai Proses Optimasi)
    end

    subgraph Abstraksi_Sistem [Abstraksi Sistem - Hill Climbing]
        E --> F[Sistem Mengambil Data Operasional & Historis Kepadatan POI]
        F --> G[Sistem Membuat Jadwal Awal Acak / Initial State]
        G --> H[Proses Generate Neighbor: Pertukaran Slot Waktu antar POI]
        H --> I[Sistem Mengevaluasi Skor Kepadatan Minimum]
        I --> J[Mencapai Local Optimum / Jadwal Terjadwal]
    end

    subgraph Result_Ditampilkan [Result untuk User]
        J --> K[/Tampilan Itinerary Terstruktur per Hari/]
        K --> L[/Detail Alokasi Waktu Kunjungan per POI/]
        L --> M[/Indikator Kepadatan di Setiap Waktu Kunjungan/]
        M --> N{Tindakan Lanjutan}
        N -- Terima --> O([Simpan atau Ekspor Jadwal])
        N -- Revisi --> B
    end
