using MusicLyricApp.Models;

namespace MusicLyricApp.Core.Service.Translate;

public interface ITranslateApi
{
    string[] Translate(string[] inputs, LanguageEnum inputLanguage, LanguageEnum outputLanguage);

    bool IsSupport(LanguageEnum inputLanguage, LanguageEnum outputLanguage);
}