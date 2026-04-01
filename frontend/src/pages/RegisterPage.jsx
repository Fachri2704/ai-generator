import { useState } from "react";

function RegisterPage({ form, onChange, onSubmit, loading }) {
  const [showPassword, setShowPassword] = useState(false);
  const [showConfirmationPassword, setShowConfirmationPassword] = useState(false);

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
              type={showPassword ? "text" : "password"}
              value={form.password}
              onChange={onChange}
              placeholder="Minimal 8 karakter"
              action={
                <button
                  type="button"
                  className="inputAction"
                  onClick={() => setShowPassword((prev) => !prev)}
                  aria-label={showPassword ? "Sembunyikan password" : "Tampilkan password"}
                  aria-pressed={showPassword}
                >
                  <EyeIcon open={showPassword} />
                </button>
              }
            />
            <Input
              label="Konfirmasi Password"
              name="password_confirmation"
              type={showConfirmationPassword ? "text" : "password"}
              value={form.password_confirmation}
              onChange={onChange}
              placeholder="Ulangi password"
              action={
                <button
                  type="button"
                  className="inputAction"
                  onClick={() => setShowConfirmationPassword((prev) => !prev)}
                  aria-label={
                    showConfirmationPassword
                      ? "Sembunyikan konfirmasi password"
                      : "Tampilkan konfirmasi password"
                  }
                  aria-pressed={showConfirmationPassword}
                >
                  <EyeIcon open={showConfirmationPassword} />
                </button>
              }
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

function Input({ label, action = null, ...props }) {
  return (
    <div>
      <label className="text-[12px] md:text-[13px] font-medium text-[#111111]">{label}</label>
      <div className="inputWrap">
        <input {...props} className={`input ${action ? "inputWithAction" : ""}`} />
        {action}
      </div>
    </div>
  );
}

function EyeIcon({ open }) {
  return open ? (
    <svg viewBox="0 0 24 24" aria-hidden="true" className="inputActionIcon">
      <path
        d="M3 3l18 18"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.8"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
      <path
        d="M10.58 10.58A2 2 0 0012 14a2 2 0 001.42-.58"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.8"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
      <path
        d="M9.88 5.09A9.77 9.77 0 0112 4c5 0 8.27 4.11 9.18 5.36a1.08 1.08 0 010 1.28 16.88 16.88 0 01-4.25 4.24"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.8"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
      <path
        d="M6.61 6.61A16.12 16.12 0 002.81 9.36a1.08 1.08 0 000 1.28C3.72 11.89 7 16 12 16a9.9 9.9 0 003.27-.54"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.8"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  ) : (
    <svg viewBox="0 0 24 24" aria-hidden="true" className="inputActionIcon">
      <path
        d="M2.81 12.36a1.08 1.08 0 010-1.28C3.72 9.83 7 5.72 12 5.72s8.28 4.11 9.19 5.36a1.08 1.08 0 010 1.28C20.28 13.61 17 17.72 12 17.72S3.72 13.61 2.81 12.36z"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.8"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
      <circle
        cx="12"
        cy="11.72"
        r="3"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.8"
      />
    </svg>
  );
}

export default RegisterPage;
