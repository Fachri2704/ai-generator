function RegisterPage({ form, onChange, onSubmit, loading }) {
  return (
    <section className="authLayout">
      <div className="authInfo">
        <h1 className="authInfoTitle">Buat akun baru dalam hitungan detik</h1>
        <p className="authInfoText">
          Daftar pakai email aktif untuk mulai bikin landing page otomatis dan lanjut ke workflow
          generate.
        </p>
      </div>
      <div className="authFormWrap">
        <div className="card cardSoft">
          <h2 className="text-[16px] md:text-[18px] font-semibold text-[#111111]">Register</h2>
          <form className="mt-4 grid gap-3" onSubmit={onSubmit}>
            <Input
              label="Nama"
              name="name"
              value={form.name}
              onChange={onChange}
              placeholder="Nama lengkap"
            />
            <Input
              label="Email"
              name="email"
              type="email"
              value={form.email}
              onChange={onChange}
              placeholder="nama@email.com"
            />
            <Input
              label="Password"
              name="password"
              type="password"
              value={form.password}
              onChange={onChange}
              placeholder="Minimal 8 karakter"
            />
            <Input
              label="Konfirmasi Password"
              name="password_confirmation"
              type="password"
              value={form.password_confirmation}
              onChange={onChange}
              placeholder="Ulangi password"
            />
            <button type="submit" disabled={loading} className="btnPrimary mt-2">
              {loading ? "Loading..." : "Daftar"}
            </button>
          </form>
        </div>
      </div>
    </section>
  );
}

function Input({ label, ...props }) {
  return (
    <div>
      <label className="text-[12px] md:text-[13px] font-medium text-[#111111]">{label}</label>
      <input {...props} className="input" />
    </div>
  );
}

export default RegisterPage;
