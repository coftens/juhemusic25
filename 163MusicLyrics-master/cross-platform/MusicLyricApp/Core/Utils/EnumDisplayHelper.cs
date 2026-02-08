using System;
using System.Collections.ObjectModel;
using System.ComponentModel;
using System.Linq;

namespace MusicLyricApp.Core.Utils;

public static class EnumDisplayHelper
{
    public class EnumDisplayItem<T> where T : Enum
    {
        public string Description { get; set; }
        public T Value { get; set; }

        public override string ToString() => Description;
    }

    public static ObservableCollection<EnumDisplayItem<T>> GetEnumDisplayCollection<T>() where T : Enum
    {
        return new ObservableCollection<EnumDisplayItem<T>>(
            Enum.GetValues(typeof(T))
                .Cast<T>()
                .Select(e => new EnumDisplayItem<T>
                {
                    Value = e,
                    Description = GetDescription(e)
                }));
    }

    private static string GetDescription<T>(T enumValue) where T : Enum
    {
        var name = enumValue.ToString();
        var fi = enumValue.GetType().GetField(name);
        if (fi?.GetCustomAttributes(typeof(DescriptionAttribute), false) is DescriptionAttribute[] attributes &&
            attributes.Any())
        {
            return attributes[0].Description;
        }

        return name;
    }
}