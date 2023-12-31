<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use CodeIgniter\Database\RawSql;

class UserController extends BaseController
{
    protected $db;
    public $cart;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
        $this->cart = \Config\Services::cart();

        $data = $this->db->table('keranjang')->select(new RawSql('DISTINCT rowid, COUNT(id) as total_produk, SUM(subtotal) as total_bayar'))->where('id_customer', session()->get('id_customer'))->get()->getResultArray();

        $lengthData = ($data[0]['total_produk'] == 0 && $data[0]['rowid'] == null) ? [] : $data;

        session()->set('total_keranjang', count($lengthData));
    }

    public function index()
    {
        return view('user/home', [
            'selesai' => $this->db->table('transaksi')->where('status_transaksi', 'Pesanan berhasil diterima oleh pemesan')->get()->getResultArray(),
            'belum' => $this->db->table('transaksi')->notLike('status_transaksi', 'Pesanan berhasil diterima oleh pemesan')->get()->getResultArray(),
        ]);
    }

    public function proses_keranjang($uid)
    {
        $data = $this->db->table('keranjang')
            ->where('rowid', $uid)
            ->get()->getResultArray();

        foreach ($data as $key => $value) {
            $getDetail = $this->db->table('produk_detail')->where('id_produk_detail', $value['id'])->get()->getRowArray();

            $qty = ($value['qty'] > $getDetail['stok_produk']) ? $getDetail['stok_produk'] : $value['qty'];

            if ($value['qty'] > $getDetail['stok_produk']) {
                session()->set('stok_status', 'Tidak Mencukupi');
            }

            $this->cart->insert([
                'id' => $value['id'],
                'id_produk' => $value['id_produk'],
                'qty' => $qty,
                'price' => $getDetail['harga_produk'],
                'name' => $value['name'],
                'label_varian' => $value['label_varian'],
                'gambar' => $getDetail['gambar_produk'],
                'stok' => $getDetail['stok_produk'],
                'id_customer' => $value['id_customer'],
            ]);
        }

        $this->db->table('keranjang')->where('rowid', $uid)->delete();

        return redirect()->to(base_url('Cart'))->with('type-status', 'success')
            ->with('message', 'Berhasil proses keranjang');
    }

    public function hapus_keranjang($uid)
    {
        $this->db->table('keranjang')->where('rowid', $uid)->delete();

        redirect()->to(base_url('Panel/Cart'))->with('type-status', 'success')
            ->with('message', 'Berhasil menghapus keranjang');
    }

    public function simpan_keranjang()
    {
        helper('text');
        $home = new Home;
        $data = [];
        $uid = random_string('alnum', 10);

        foreach ($this->cart->contents() as $key => $value) {
            $data[$key] = [
                'id' => $value['id'],
                'id_produk' => $value['id_produk'],
                'qty' => $value['qty'],
                'price' => $value['price'],
                'name' => $value['name'],
                'label_varian' => $value['label_varian'],
                'gambar' => $value['gambar'],
                'stok' => $value['stok'],
                'id_customer' => $value['id_customer'],
                'subtotal' => $value['subtotal'],
                'rowid' => $uid
            ];
        }

        $this->db->table('keranjang')->insertBatch($data);

        $home->clear_cart();

        return redirect()->to(base_url('Panel/Cart'))->with('type-status', 'success')
            ->with('message', 'Berhasil menyimpan keranjang');
    }

    public function keranjang()
    {
        return view('user/cart', [
            'data' => $this->db->table('keranjang')->select(new RawSql('DISTINCT rowid, COUNT(id) as total_produk, SUM(subtotal) as total_bayar'))->where('id_customer', session()->get('id_customer'))->orderBy('rowid', 'DESC')->get()->getResultArray()
        ]);
    }

    public function transaksi()
    {
        return view('user/transaksi', [
            'data' => $this->db->table('transaksi')->orderBy('id_transaksi', 'DESC')->get()->getResultArray()
        ]);
    }

    public function invoice($id)
    {
        return view('user/invoice', [
            'dataTransaksi' => $this->db->table('transaksi')->where('id_transaksi', $id)->get()->getRowArray(),
            'dataDetail' => $this->db->table('transaksi_detail')->where('id_transaksi', $id)->get()->getResultArray(),
            'dataToko' => $this->db->table('informasi_toko')->where('id_toko', '1')->get()->getRowArray(),
            'dataUser' => $this->db->table('customer')->where('id_customer', session()->get('id_customer'))->get()->getRowArray()
        ]);
    }

    public function checkout()
    {
        helper('text');

        $home = new Home;
        $requestText = $this->request->getPost('request') ?? '';

        if (isset($_SESSION['logged_in_customer']) and $_SESSION['logged_in_customer'] == TRUE) {
            $q = 0;
            $get = [];
            $data = [];
            $hargaarr = [];
            $uid = random_string();

            foreach ($this->cart->contents() as $item) {
                $produk = $this->db->table('produk_detail')->where('id_produk_detail', $item['id'])->get()->getRowArray();

                if ($produk['stok_produk'] < $item['qty']) {
                    return redirect()->to(base_url('Cart'))->with('type-status', 'error')
                        ->with('message', 'Terdapat Stok kurang pada keranjang anda, silahkan ditambahkan ulang');
                }

                $get[] = $produk;
                $get[$q]['nama_produk'] = $item['name'];
                $get[$q]['qty'] = $item['qty'];
                $get[$q]['total_harga'] = $item['qty'] * $item['price'];
                $stok = $produk['stok_produk'] - $item['qty'];
                $hargaarr[] = $item['qty'] * $item['price'];

                $this->db->table('produk_detail')->where('id_produk_detail', $item['id'])->update([
                    'stok_produk' => $stok
                ]);

                $q++;
            }

            $total_harga = array_sum($hargaarr);

            if (count($get) >= 10) {
                $total_harga = array_sum($hargaarr) - (array_sum($hargaarr) * (20 / 100));
            } else if (count($get) >= 7) {
                $total_harga = array_sum($hargaarr) - (array_sum($hargaarr) * (10 / 100));
            } else if (count($get) >= 5) {
                $total_harga = array_sum($hargaarr) - (array_sum($hargaarr) * (5 / 100));
            }

            $dataTransaksi = [
                'uid' => $uid,
                'id_customer' => session()->get('id_customer'),
                'total_produk' => count($get),
                'total_bayar' => $total_harga,
                'batas_pembayaran' => date('Y-m-d', strtotime(date('Y-m-d') . ' + 1 Days')),
                'status_transaksi' => 'Menunggu Bukti Pembayaran',
                'id_ongkir' => session()->get('id_ongkir'),
                'alamat' => session()->get('alamat'),
                'kota_kab' => session()->get('kota_kab'),
                'kec_desa' => session()->get('kec_desa'),
                'nomor_hp' => session()->get('nomor_hp'),
                'pesan' => $requestText
            ];

            $this->db->table('transaksi')->insert($dataTransaksi);
            $getTransaksi = $this->db->table('transaksi')->where('uid', $uid)->get()->getRowArray();

            foreach ($get as $item) {
                $data[] = [
                    'id_transaksi' => $getTransaksi['id_transaksi'],
                    'id_produk' => $item['id_produk'],
                    'id_produk_detail' => $item['id_produk_detail'],
                    'id_customer' => session()->get('id_customer'),
                    'nama_produk' => $item['nama_produk'],
                    'kuantitas_produk' => $item['qty'],
                    'harga_produk' => $item['harga_produk'],
                    'label_varian' => $item['label_warna_produk'],
                    'subtotal' => $item['total_harga']
                ];
            }

            $this->db->table('transaksi_detail')->insertBatch($data);

            $home->clear_cart();

            return redirect()->to(base_url('Panel/Transaksi'));
        } else {
            return redirect()->to(base_url('Login'))->with('type-status', 'error')
                ->with('message', 'Silahkan Login Terlebih Dahulu');
        }
    }

    public function upload_bukti($id)
    {
        $rules = [
            'gambar' => 'is_image[gambar]'
        ];

        if (!$this->validate($rules)) {
            return redirect()->to(previous_url())->with('type-status', 'error')->with('dataMessage', $this->validator->getErrors());
        }

        $filename = $this->request->getFile('gambar')->getRandomName();

        $this->db->table('transaksi')->where('id_transaksi', $id)->update([
            'bukti_bayar' => $filename,
            'status_transaksi' => 'Menunggu Validasi Bukti Bayar'
        ]);

        if (!$this->request->getFile('gambar')->hasMoved()) {
            $this->request->getFile('gambar')->move('uploads', $filename);
        }

        return redirect()->to(previous_url())->with('type-status', 'success')
            ->with('message', 'Bukti pembayaran berhasil diupload');
    }

    public function konfirmasi_pesanan($id)
    {
        $this->db->table('transaksi')->where('id_transaksi', $id)->update([
            'status_transaksi' => 'Pesanan berhasil diterima oleh pemesan'
        ]);

        return redirect()->to(previous_url())->with('type-status', 'success')
            ->with('message', 'Pesanan berhasil dikirim');
    }

    public function testimoni()
    {
        $opt = [];
        $get = $this->db->table('transaksi')
            ->where('id_customer', session()->get('id_customer'))
            ->where('status_transaksi', 'Pesanan berhasil diterima oleh pemesan')
            ->get()
            ->getResultArray();

        foreach ($get as $key => $value) {
            $getDetail = $this->db->table('transaksi_detail')->where('id_transaksi', $value['id_transaksi'])->get()->getResultArray();

            foreach ($getDetail as $ky => $val) {
                $opt[] = [
                    'id_transaksi_detail' => $val['id_transaksi_detail'],
                    'id_produk' => $val['id_produk'],
                    'nama_produk' => $val['nama_produk'],
                    'varian' => $val['label_varian']
                ];
            }
        }

        foreach ($opt as $item) {
            $check = $this->db->table('review')->where('id_transaksi_detail', $item['id_transaksi_detail'])->get()->getRowArray();

            if ($check != null) {
                $i = array_search($item['id_transaksi_detail'], $opt);

                unset($opt[$i]);
            }
        }

        return view('user/testimoni', [
            'data' => $this->db->table('review')->where('id_customer', session()->get('id_customer'))->get()->getResultArray(),
            'opt' => $opt
        ]);
    }

    public function testimoni_save()
    {
        $rules = [
            'id_transaksi_detail' => 'required',
            'bintang' => 'required',
            'deskripsi' => 'required'
        ];

        if (!$this->validate($rules)) {
            return redirect()->to(previous_url())->with('type-status', 'error')->with('dataMessage', $this->validator->getErrors());
        }

        $get = $this->db->table('transaksi_detail')->where('id_transaksi_detail', $this->request->getPost('id_transaksi_detail'))->get()->getRowArray();

        $this->db->table('review')->insert([
            'id_produk' => $get['id_produk'],
            'id_customer' => $get['id_customer'],
            'id_transaksi_detail' => $get['id_transaksi_detail'],
            'varian_produk' => $get['label_varian'],
            'bintang' => $this->request->getPost('bintang'),
            'deskripsi' => $this->request->getPost('deskripsi'),
        ]);

        return redirect()->to(previous_url())->with('type-status', 'success')
            ->with('message', 'Testimoni berhasil ditambahkan');
    }

    public function testimoni_edit($id)
    {
        $rules = [
            'id_transaksi_detail' => 'required',
            'bintang' => 'required',
            'deskripsi' => 'required'
        ];

        if (!$this->validate($rules)) {
            return redirect()->to(previous_url())->with('type-status', 'error')->with('dataMessage', $this->validator->getErrors());
        }

        $get = $this->db->table('transaksi_detail')->where('id_transaksi_detail', $this->request->getPost('id_transaksi_detail'))->get()->getRowArray();

        $this->db->table('review')->where('id_review', $id)->update([
            'id_produk' => $get['id_produk'],
            'id_customer' => $get['id_customer'],
            'id_transaksi_detail' => $get['id_transaksi_detail'],
            'varian_produk' => $get['label_varian'],
            'bintang' => $this->request->getPost('bintang'),
            'deskripsi' => $this->request->getPost('deskripsi'),
        ]);

        return redirect()->to(previous_url())->with('type-status', 'success')
            ->with('message', 'Testimoni berhasil ditambahkan');
    }

    public function updatePassword()
    {
        $rules = [
            'old' => 'required',
            'new' => 'required',
            'conf' => 'matches[new]'
        ];

        $check = $this->db->table('customer')->where('id_customer', session()->get('id_customer'))->get()->getRowArray();

        if (!password_verify((string) $this->request->getPost('old'), $check['password'])) {
            return redirect()->to(base_url('Panel'))->with('type-status', 'error')->with('message', 'Password Lama Tidak Benar');
        }

        if (!$this->validate($rules)) {
            return redirect()->to(base_url('Panel'))->with('type-status', 'error')->with('dataMessage', $this->validator->getErrors());
        }

        $this->db->table('customer')->where('id_customer', session()->get('id_customer'))->update([
            'password' => password_hash((string) $this->request->getPost('new'), PASSWORD_DEFAULT),
        ]);

        return redirect()->to(base_url('Panel'))->with('type-status', 'success')->with('message', 'Password berhasil diperbarui');
    }

    public function updateInformasi()
    {
        $rules = [
            'id_ongkir' => 'required',
            'kec_desa' => 'required',
            'alamat' => 'required'
        ];

        if (!$this->validate($rules)) {
            return redirect()->to(base_url('Panel'))->with('type-status', 'error')->with('dataMessage', $this->validator->getErrors());
        }

        $get = $this->db->table('ongkir')->where('id_ongkir', $this->request->getPost('id_ongkir'))->get()->getRowArray();

        $this->db->table('customer')->where('id_customer', session()->get('id_customer'))->update([
            'id_ongkir' => $get['id_ongkir'],
            'kota_kab' => $get['nama_kota'],
            'kec_desa' => $this->request->getPost('kec_desa'),
            'alamat' => $this->request->getPost('alamat'),
        ]);

        session()->set([
            'id_ongkir' => $get['id_ongkir'],
            'kota_kab' => $get['nama_kota'],
            'kec_desa' => $this->request->getPost('kec_desa'),
            'alamat' => $this->request->getPost('alamat'),
            'alamat_customer' => $this->request->getPost('alamat'),
        ]);

        return redirect()->to(base_url('Panel'))->with('type-status', 'success')->with('message', 'Informasi Pengiriman berhasil diperbarui');
    }

    public function hapus_transaksi($id)
    {
        $this->db->table('transaksi')->where('id_transaksi', $id)->delete();
        $this->db->table('transaksi_detail')->where('id_transaksi', $id)->delete();

        return redirect()->to(base_url('Panel/Transaksi'))->with('type-status', 'success')
            ->with('message', 'Transaksi Berhasil terhapus');
    }

    public function transaksi_gagal($id)
    {
        $this->db->table('transaksi')->where('id_transaksi', $id)->update([
            'status_transaksi' => 'GAGAL'
        ]);

        return $this->response->setJSON('Transaksi Gagal');
    }
}
