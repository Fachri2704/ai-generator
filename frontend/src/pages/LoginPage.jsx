function LoginPage({ form, onChange, onSubmit, loading }) {
  return (
    <section className="authLayout">
      <div className="authInfo">
        <h1 className="authInfoTitle">Masuk untuk mulai generate landing page</h1>
        <p className="authInfoText">
          Gunakan akun kamu untuk akses fitur generate, simpan workflow, dan lanjutkan project
          kapan saja.
        </p>
      </div>
      <div className="authFormWrap">
        <div className="card cardSoft">
          <h2 className="text-[16px] md:text-[18px] font-semibold text-[#111111]">Login</h2>
          <form className="mt-4 grid gap-3" onSubmit={onSubmit}>
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
            <button type="submit" disabled={loading} className="btnPrimary mt-2">
              {loading ? "Loading..." : "Masuk"}
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

export default LoginPage;
